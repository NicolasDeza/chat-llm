<template>
    <div>
        <h1>Conversation</h1>
        <ul>
            <li v-for="(msg, index) in messages" :key="index">
                {{ msg.content }} <span v-if="msg.isComplete">(Terminé)</span>
            </li>
        </ul>
    </div>
</template>

<script setup>
import { ref, onMounted } from "vue";

const messages = ref([]);

onMounted(() => {
    console.log("ChatWindow monté"); // Vérifie que le composant est monté

    // Assurez-vous d'utiliser le bon identifiant de conversation (ici "chat.1")
    const channelName = "chat.1";
    console.log("Tentative d'abonnement au canal :", channelName);

    window.Echo.private(channelName)
        .subscribed(() => {
            console.log("✅ Abonné au canal", channelName);
        })
        .error((error) => {
            console.error(
                "❌ Erreur lors de l'abonnement au canal",
                channelName,
                ":",
                error
            );
        })
        .listen(".message.streamed", (data) => {
            console.log("Message reçu via", channelName, ":", data);
            messages.value.push(data);
        });
});
</script>
