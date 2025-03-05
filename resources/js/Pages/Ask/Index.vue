<script setup>
import { ref, watch, nextTick, computed, toRaw, onMounted } from "vue";
import { useForm, usePage } from "@inertiajs/vue3";
import MarkdownIt from "markdown-it";
import hljs from "highlight.js";
import "highlight.js/styles/github-dark.css";

// Initialisation de l'état avec une meilleure gestion des valeurs par défaut
const page = usePage();

// Remplacer computed par une ref réactive
const conversations = ref(
    Array.isArray(page.props.conversations) ? page.props.conversations : []
);

// Conversion des props en données réactives avec vérification
const models = computed(() => {
    const modelData = toRaw(page.props.models);
    return Array.isArray(modelData) ? modelData : [];
});

const selectedModel = ref(page.props.selectedModel || null);

const messages = ref([]);
const loading = ref(false);
const selectedConversation = ref(null);
const currentConversationId = ref(null);
const editingTitle = ref(null);
const newTitle = ref("");
// Ajout de la ref pour l'input du titre
const titleInput = ref(null);
const flashMessage = ref("");
const flashError = ref("");

// Debug logs
// console.log("Models:", toRaw(models.value));
//console.log("Conversations:", toRaw(conversations.value));
//console.log("Messages:", toRaw(messages.value));

// Initialisation de Markdown
const md = new MarkdownIt({
    highlight: function (str, lang) {
        if (lang && hljs.getLanguage(lang)) {
            try {
                return hljs.highlight(str, { language: lang }).value;
            } catch (__) {
                return "";
            }
        }
        return "";
    },
    html: true,
    breaks: true,
});

// Formulaire avec valeur par défaut sécurisée
const form = useForm({
    message: "",
    model: selectedModel.value || "",
    conversation_id: null,
});

// Gestion des conversations
const selectConversation = async (conversation) => {
    if (!conversation?.id || currentConversationId.value === conversation.id)
        return;

    try {
        loading.value = true;
        currentConversationId.value = conversation.id;
        selectedConversation.value = conversation;

        setupChatSubscription(conversation.id);

        // Mettre à jour le modèle sélectionné avec celui de la conversation
        if (conversation.model) {
            form.model = conversation.model;
        }

        // Charger les messages de la conversation
        const response = await axios.get(
            route("messages.index", conversation.id)
        );
        if (response?.data?.messages) {
            messages.value = response.data.messages;
            // Force le scroll vers le bas lors du clic sur une conversation
            await scrollToBottom(true);
        }
    } catch (error) {
        console.error("Erreur lors du chargement des messages:", error);
        flashError.value = "Erreur lors du chargement des messages";
    } finally {
        loading.value = false;
    }
};

const createConversation = async () => {
    if (loading.value) return;

    try {
        loading.value = true;
        const response = await axios.post(route("chat.store"));

        if (response?.data?.conversations) {
            conversations.value = response.data.conversations;
            if (response.data.conversation) {
                await selectConversation(response.data.conversation);
            }
        }
    } catch (error) {
        console.error("Erreur lors de la création de la conversation:", error);
        flashError.value = "Erreur lors de la création de la conversation";
    } finally {
        loading.value = false;
    }
};

const updateConversationTitle = async (conversation) => {
    if (!conversation?.id || !newTitle.value?.trim()) {
        newTitle.value = conversation?.title || "";
        editingTitle.value = null;
        return;
    }

    try {
        const response = await axios.post(
            route("chat.updateTitle", conversation.id),
            {
                title: newTitle.value.trim(),
            }
        );

        if (response?.data?.conversation) {
            const index = conversations.value.findIndex(
                (c) => c.id === response.data.conversation.id
            );
            if (index !== -1) {
                conversations.value[index] = response.data.conversation;
            }
        }
    } catch (error) {
        console.error("Erreur lors de la mise à jour du titre:", error);
        flashError.value = "Erreur lors de la mise à jour du titre";
    } finally {
        editingTitle.value = null;
    }
};

// Gestion des messages
const sendMessage = async (isRetry = false) => {
    if (
        !form.message.trim() ||
        !selectedConversation.value?.id ||
        loading.value
    )
        return;

    // console.log("Envoi du message:", form.message);

    const tempMessage = form.message.trim();
    try {
        loading.value = true;
        form.conversation_id = selectedConversation.value.id;

        messages.value.push({ role: "user", content: tempMessage });
        messages.value.push({
            role: "assistant",
            content: "L'IA réfléchit...",
            isLoading: true,
        });

        // Forcer le scroll vers le bas uniquement lors de l'envoi initial
        await scrollToBottom(true);

        const processedMessage = await processMessage(tempMessage);

        // console.log("Message traité:", processedMessage);

        const response = await axios.post(
            route("messages.stream", selectedConversation.value.id),
            {
                message: processedMessage,
                model: form.model,
            },
            {
                timeout: 120000, // 2 minutes
                timeoutErrorMessage:
                    "Le délai de réponse a été dépassé. Veuillez réessayer.",
            }
        );

        form.reset("message");
        // Ne pas forcer le scroll ici

        // Mettre à jour l'activité et réordonner la conversation
        selectedConversation.value.last_activity = new Date().toISOString();
        updateConversationOrder();
    } catch (error) {
        console.error("Erreur lors de l'envoi du message:", error);

        // Supprimer le message "L'IA réfléchit..." en cas d'erreur
        messages.value = messages.value.filter(
            (msg) =>
                msg.content !== "L'IA réfléchit..." &&
                msg.content !== tempMessage
        );

        // Message d'erreur personnalisé pour le timeout
        if (
            error.code === "ECONNABORTED" ||
            error.response?.data?.code === "TIMEOUT"
        ) {
            flashError.value =
                "Le délai de réponse a été dépassé. Veuillez réessayer.";
        } else {
            flashError.value =
                "Une erreur est survenue lors de l'envoi du message";
        }

        // Réessayer automatiquement une fois en cas de timeout
        if (error.code === "ECONNABORTED" && !isRetry) {
            await sendMessage(true);
        }
    } finally {
        loading.value = false;
    }
};

// Utilitaires
// Modification de scrollToBottom pour accepter un paramètre facultatif "force"
const scrollToBottom = async (force = false) => {
    await nextTick();
    const chatContainer = document.querySelector(".chat-container");
    if (chatContainer) {
        if (force) {
            chatContainer.scrollTop = chatContainer.scrollHeight;
            return;
        }
        const threshold = 50; // Seuil en pixels
        if (
            chatContainer.scrollHeight -
                chatContainer.scrollTop -
                chatContainer.clientHeight <=
            threshold
        ) {
            chatContainer.scrollTop = chatContainer.scrollHeight;
        }
    }
};

// Ajoutez cette fonction pour formater la date
const formatDate = (dateString) => {
    if (!dateString) return "";
    const date = new Date(dateString);
    if (isNaN(date.getTime())) return "";
    return date.toLocaleDateString("fr-FR", {
        year: "numeric",
        month: "short",
        day: "numeric",
    });
};

// Ajout des refs pour les instructions personnalisées
const showInstructionsModal = ref(false);
const customInstruction = ref(
    page.props.customInstruction || {
        about_user: "",
        preference: "",
    }
);

// Fonction pour sauvegarder les instructions
const saveInstructions = async () => {
    try {
        loading.value = true;
        const response = await axios.post(route("custom-instructions.store"), {
            about_user: customInstruction.value.about_user,
            preference: customInstruction.value.preference,
        });

        if (response.data.instruction) {
            customInstruction.value = response.data.instruction;
            showInstructionsModal.value = false;
            flashMessage.value = "Instructions personnalisées sauvegardées";
        }
    } catch (error) {
        flashError.value = "Erreur lors de la sauvegarde des instructions";
    } finally {
        loading.value = false;
    }
};

watch(selectedModel, (newModel) => {
    if (newModel) {
        form.model = newModel;
    }
});
// Ajout d'un watcher sur le modèle pour sauvegarder le choix utilisateur et celui de la conversation (si sélectionnée)
watch(
    () => form.model,
    async (newModel, oldModel) => {
        if (newModel && newModel !== oldModel) {
            // Mettre à jour localement la conversation sélectionnée et l'array des conversations
            if (selectedConversation.value) {
                selectedConversation.value.model = newModel;
                const idx = conversations.value.findIndex(
                    (c) => c.id === selectedConversation.value.id
                );
                if (idx !== -1) {
                    conversations.value[idx].model = newModel;
                }
            }
            try {
                await axios.post(route("user.updateModel"), {
                    model: newModel,
                    conversation_id: selectedConversation.value?.id || null,
                });
            } catch (error) {
                console.error("Erreur lors de la sauvegarde du modèle:", error);
            }
        }
    }
);

const commands = ref([]);
const showCommandsModal = ref(false);

const newCommand = ref({
    name: "",
    command: "",
    description: "",
    prompt: "",
});

const saveCommand = async () => {
    try {
        loading.value = true;
        const response = await axios.post(
            route("custom-commands.store"),
            newCommand.value
        );
        if (response.data.command) {
            commands.value.push(response.data.command);
            newCommand.value = {
                name: "",
                command: "",
                description: "",
                prompt: "",
            };
            flashMessage.value = "Commande personnalisée sauvegardée";
        }
    } catch (error) {
        flashError.value = "Erreur lors de la sauvegarde de la commande";
    } finally {
        loading.value = false;
    }
};

// Charger les commandes au démarrage
const loadCommands = async () => {
    try {
        const response = await axios.get(route("custom-commands.index"));
        commands.value = response.data.commands;
    } catch (error) {
        console.error("Erreur lors du chargement des commandes:", error);
    }
};

// Vérifier si le message commence par une commande
const processMessage = async (message) => {
    if (message.startsWith("/")) {
        const command = commands.value.find((cmd) =>
            message.startsWith(cmd.command)
        );

        if (command) {
            // Remplacer la commande par son prompt
            return (
                command.prompt +
                " " +
                message.slice(command.command.length).trim()
            );
        }
    }
    return message;
};

import Pusher from "pusher-js";

window.Pusher = Pusher;

// const conversationId = 1;
const currentChannel = ref(null);

const setupChatSubscription = (conversationId) => {
    if (!conversationId) return;

    const channelName = `chat.${conversationId}`;
    // console.log("Tentative d'abonnement au canal :", channelName);

    // Leave previous channel if it exists
    if (currentChannel.value) {
        // console.log(
        //     "👋 Désinscription du canal précédent:",
        //     currentChannel.value
        // );
        window.Echo.leave(currentChannel.value);
    }

    // Update current channel
    currentChannel.value = channelName;

    window.Echo.private(channelName)
        .subscribed(() => {
            // console.log("✅ Abonné au canal", channelName);
        })
        .error((error) => {
            console.error(
                "❌ Erreur lors de l'abonnement au canal",
                channelName,
                error
            );
        })
        .listen(".message.streamed", (event) => {
            // Gestion du streaming du titre
            if (event.isTitle) {
                // console.log("Réception du titre:", event.content);
                const index = conversations.value.findIndex(
                    (c) => c.id === currentConversationId.value
                );
                if (index !== -1) {
                    conversations.value[index].title = event.content;
                    // Forcer la réactivité
                    conversations.value = [...conversations.value];
                }
                return;
            }

            // Gestion normale des messages
            const lastMessage = messages.value[messages.value.length - 1];
            if (!lastMessage || lastMessage.role !== "assistant") {
                // console.log("⚠️ Aucun message assistant ciblé pour concaténer");
                return;
            }

            if (lastMessage.isLoading && event.content) {
                lastMessage.isLoading = false;
                lastMessage.content = "";
            }

            if (!event.isComplete) {
                lastMessage.content += event.content;
                nextTick(() => scrollToBottom());
            }
        });
};

// Nouvelle fonction de mise à jour de l'ordre des conversations
const updateConversationOrder = () => {
    conversations.value = conversations.value.sort(
        (a, b) => new Date(b.last_activity) - new Date(a.last_activity)
    );
};

onMounted(() => {
    // Load initial conversation if available
    if (page.props.conversation?.id) {
        currentConversationId.value = page.props.conversation.id;
        setupChatSubscription(currentConversationId.value);
    }

    // Load commands
    loadCommands();
});
</script>

<template>
    <div class="flex w-screen h-screen text-white bg-gray-900">
        <!-- Sidebar avec scroll -->
        <aside
            class="flex flex-col w-1/4 p-4 bg-gray-800 border-r border-gray-700"
        >
            <h2 class="mb-4 text-xl font-bold">Conversations</h2>

            <button
                @click="createConversation"
                class="flex items-center justify-center p-2 mb-4 text-white transition bg-blue-500 rounded hover:bg-blue-600"
                :disabled="loading"
            >
                <span v-if="!loading">+ Nouvelle conversation</span>
                <span v-else>Chargement...</span>
            </button>

            <!-- Conteneur avec scroll pour la liste des conversations -->
            <div class="flex-1 min-h-0 overflow-y-auto">
                <div v-if="conversations?.length" class="space-y-2">
                    <div
                        v-for="conversation in conversations"
                        :key="conversation?.id"
                        class="p-3 transition-colors duration-200 rounded-lg cursor-pointer"
                        :class="{
                            'bg-gray-700':
                                selectedConversation?.id === conversation?.id,
                            'hover:bg-gray-700':
                                selectedConversation?.id !== conversation?.id,
                        }"
                    >
                        <div
                            class="flex items-center justify-between"
                            @click="selectConversation(conversation)"
                        >
                            <div v-if="editingTitle === conversation?.id">
                                <input
                                    v-model="newTitle"
                                    @blur="
                                        updateConversationTitle(conversation)
                                    "
                                    @keyup.enter="
                                        updateConversationTitle(conversation)
                                    "
                                    class="w-full px-2 py-1 text-white bg-gray-600 rounded"
                                    ref="titleInput"
                                />
                            </div>
                            <div
                                v-else
                                class="font-medium"
                                @dblclick="
                                    () => {
                                        editingTitle = conversation?.id;
                                        newTitle = conversation?.title || '';
                                        nextTick(() => {
                                            titleInput.value?.focus();
                                        });
                                    }
                                "
                            >
                                {{
                                    conversation?.title ||
                                    "Nouvelle conversation"
                                }}
                            </div>
                            <div class="text-sm text-gray-400">
                                {{ formatDate(conversation?.last_activity) }}
                            </div>
                        </div>
                    </div>
                </div>
                <div v-else class="py-4 text-center text-gray-400">
                    Aucune conversation
                </div>
            </div>

            <!-- Bouton instructions en bas fixe -->
            <button
                @click="showInstructionsModal = true"
                class="w-full p-2 mt-4 text-white transition bg-gray-700 rounded hover:bg-gray-600"
            >
                Instructions personnalisées
            </button>

            <!-- Ajouter un bouton pour gérer les commandes -->
            <button
                @click="showCommandsModal = true"
                class="w-full p-2 mt-2 text-white transition bg-gray-700 rounded hover:bg-gray-600"
            >
                Commandes personnalisées
            </button>
        </aside>

        <!-- Zone principale -->
        <main class="flex flex-col flex-1 h-full">
            <div class="flex flex-col w-full h-full p-6 bg-gray-800">
                <!-- Sélecteur de modèle -->
                <div class="mb-4">
                    <label class="block mb-2 font-medium text-gray-200"
                        >Modèle utilisé :</label
                    >
                    <select
                        v-model="form.model"
                        class="w-full p-2 text-white bg-gray-700 border rounded"
                        :disabled="loading"
                    >
                        <option
                            v-for="model in models"
                            :key="model?.id"
                            :value="model?.id"
                        >
                            {{ model?.name }}
                        </option>
                    </select>
                </div>

                <h1 class="mb-4 text-2xl font-semibold text-center">
                    Que puis-je faire pour vous ?
                </h1>

                <!-- Messages -->
                <div
                    class="flex-1 p-4 mb-4 overflow-y-auto bg-gray-700 border rounded-lg chat-container"
                >
                    <div v-if="messages?.length">
                        <div
                            v-for="(msg, index) in messages"
                            :key="index"
                            class="mb-4 last:mb-0"
                        >
                            <div
                                class="flex"
                                :class="
                                    msg?.role === 'user'
                                        ? 'justify-end'
                                        : 'justify-start'
                                "
                            >
                                <div
                                    class="max-w-[80%] p-3 rounded-lg shadow-md"
                                    :class="
                                        msg?.role === 'user'
                                            ? 'bg-blue-500'
                                            : 'bg-gray-600'
                                    "
                                >
                                    <strong
                                        >{{
                                            msg?.role === "user"
                                                ? "Vous"
                                                : "Assistant"
                                        }}:</strong
                                    >
                                    <div
                                        class="mt-1 prose prose-invert max-w-none"
                                        v-html="md.render(msg?.content || '')"
                                    />
                                </div>
                            </div>
                        </div>
                    </div>
                    <div v-else class="py-4 text-center text-gray-400">
                        Commencez une conversation...
                    </div>
                </div>

                <!-- Messages d'erreur -->
                <div
                    v-if="flashError"
                    class="p-3 mb-4 text-white bg-red-500 rounded"
                >
                    {{ flashError }}
                </div>

                <!-- Formulaire -->
                <form @submit.prevent="sendMessage" class="flex space-x-2">
                    <input
                        v-model="form.message"
                        type="text"
                        class="flex-1 p-3 text-white bg-gray-700 border rounded-lg focus:ring focus:ring-blue-300"
                        placeholder="Posez votre question..."
                        :disabled="loading || !selectedConversation"
                    />
                    <button
                        type="submit"
                        class="px-6 py-3 text-white transition bg-blue-500 rounded-lg hover:bg-blue-600 disabled:opacity-50 disabled:cursor-not-allowed"
                        :disabled="
                            loading ||
                            !selectedConversation ||
                            !form.message?.trim()
                        "
                    >
                        {{ loading ? "Envoi..." : "Envoyer" }}
                    </button>
                </form>
            </div>
        </main>

        <!-- Modal Instructions Personnalisées -->
        <div
            v-if="showInstructionsModal"
            class="fixed inset-0 flex items-center justify-center p-4 bg-black bg-opacity-50"
        >
            <div class="w-full max-w-2xl p-6 bg-gray-800 rounded-lg">
                <h2 class="mb-4 text-xl font-bold">
                    Instructions Personnalisées
                </h2>

                <div class="space-y-4">
                    <div>
                        <label class="block mb-2 text-sm font-medium">
                            Que souhaitez-vous que l'IA sache à propos de vous ?
                        </label>
                        <textarea
                            v-model="customInstruction.about_user"
                            class="w-full h-32 p-2 bg-gray-700 rounded"
                            placeholder="Ex: Je suis développeur PHP avec 5 ans d'expérience..."
                        ></textarea>
                    </div>

                    <div>
                        <label class="block mb-2 text-sm font-medium">
                            Comment souhaitez-vous que l'IA vous réponde ?
                        </label>
                        <textarea
                            v-model="customInstruction.preference"
                            class="w-full h-32 p-2 bg-gray-700 rounded"
                            placeholder="Ex: Réponses concises avec des exemples de code..."
                        ></textarea>
                    </div>
                </div>

                <div class="flex justify-end mt-6 space-x-3">
                    <button
                        @click="showInstructionsModal = false"
                        class="px-4 py-2 bg-gray-600 rounded hover:bg-gray-500"
                    >
                        Annuler
                    </button>
                    <button
                        @click="saveInstructions"
                        class="px-4 py-2 bg-blue-500 rounded hover:bg-blue-600"
                        :disabled="loading"
                    >
                        {{ loading ? "Sauvegarde..." : "Sauvegarder" }}
                    </button>
                </div>
            </div>
        </div>

        <!-- Modal pour les commandes -->
        <div
            v-if="showCommandsModal"
            class="fixed inset-0 flex items-center justify-center p-4 bg-black bg-opacity-50"
        >
            <div class="w-full max-w-2xl p-6 bg-gray-800 rounded-lg">
                <h2 class="mb-4 text-xl font-bold">Commandes Personnalisées</h2>

                <!-- Liste des commandes existantes -->
                <div class="mb-6 space-y-4">
                    <div
                        v-for="cmd in commands"
                        :key="cmd.id"
                        class="p-4 bg-gray-700 rounded"
                    >
                        <div class="font-bold">{{ cmd.name }}</div>
                        <div class="text-gray-400">{{ cmd.command }}</div>
                        <div class="text-sm">{{ cmd.description }}</div>
                    </div>
                </div>

                <!-- Formulaire pour ajouter une nouvelle commande -->
                <form @submit.prevent="saveCommand" class="space-y-4">
                    <div>
                        <label class="block mb-2 text-sm font-medium"
                            >Nom de la commande</label
                        >
                        <input
                            type="text"
                            v-model="newCommand.name"
                            class="w-full p-2 bg-gray-700 rounded"
                            placeholder="Ex: Météo"
                        />
                    </div>

                    <div>
                        <label class="block mb-2 text-sm font-medium"
                            >Commande (/)</label
                        >
                        <input
                            type="text"
                            v-model="newCommand.command"
                            class="w-full p-2 bg-gray-700 rounded"
                            placeholder="Ex: /meteo"
                        />
                    </div>

                    <div>
                        <label class="block mb-2 text-sm font-medium"
                            >Description</label
                        >
                        <input
                            type="text"
                            v-model="newCommand.description"
                            class="w-full p-2 bg-gray-700 rounded"
                            placeholder="Ex: Affiche la météo pour une ville"
                        />
                    </div>

                    <div>
                        <label class="block mb-2 text-sm font-medium"
                            >Prompt</label
                        >
                        <textarea
                            v-model="newCommand.prompt"
                            class="w-full h-32 p-2 bg-gray-700 rounded"
                            placeholder="Ex: Donne la météo pour la ville de"
                        ></textarea>
                    </div>
                </form>

                <div class="flex justify-end mt-6 space-x-3">
                    <button
                        @click="showCommandsModal = false"
                        class="px-4 py-2 bg-gray-600 rounded hover:bg-gray-500"
                    >
                        Fermer
                    </button>
                    <button
                        @click="saveCommand"
                        class="px-4 py-2 bg-blue-500 rounded hover:bg-blue-600"
                        :disabled="loading"
                    >
                        Ajouter une commande
                    </button>
                </div>
            </div>
        </div>
    </div>
</template>
