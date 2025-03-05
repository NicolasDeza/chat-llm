<?php

namespace App\Http\Controllers;

use App\Models\Message;
use App\Models\Conversation;
use App\Models\CustomInstruction;
use App\Services\ChatService;
use App\Events\ChatMessageStreamed;
use Illuminate\Http\Request;
use Inertia\Inertia;

class MessageController extends Controller
{
    protected $chatService;

    public function __construct(ChatService $chatService)
    {
        $this->chatService = $chatService;
    }

    /**
     * Récupérer les messages d'une conversation
     */
    public function index($conversationId)
    {
        $messages = Message::where('conversation_id', $conversationId)
            ->orderBy('created_at', 'asc')
            ->get();

        return response()->json(['messages' => $messages]);
    }

    /**
     * Méthode principale pour le streaming des messages
     */
    public function streamMessage(Conversation $conversation, Request $request)
    {
        // Augmenter le timeout PHP
        set_time_limit(120); // 2 minutes

        $request->validate([
            'message' => 'required|string',
            'model'   => 'nullable|string',
        ]);

        try {
            // 1. Sauvegarder le message de l'utilisateur
            $conversation->messages()->create([
                'content' => $request->input('message'),
                'role'    => 'user',
            ]);

            // 2. Nom du canal
            $channelName = "chat.{$conversation->id}";

            // 3. Récupérer l'historique des messages
            $messages = $conversation->messages()
                ->orderBy('created_at', 'asc')
                ->get()
                ->map(fn($msg) => [
                    'role'    => $msg->role,
                    'content' => $msg->content,
                ])
                ->toArray();

            // 4. Obtenir le flux depuis le ChatService
            $stream = $this->chatService->streamConversation(
                messages: $messages,
                model: $conversation->model ?? $request->user()->last_used_model ?? ChatService::DEFAULT_MODEL,
                temperature: 0.7
            );

            // 5. Créer le message "assistant" dans la BD (vide pour l'instant)
            $assistantMessage = $conversation->messages()->create([
                'content' => '',
                'role'    => 'assistant',
            ]);

            // 6. Variables pour accumuler la réponse
            $fullResponse = '';
            $tokenBuffer = '';
            $tokenCount = 0;
            $maxTokensPerBatch = 5;  // Réduit de 15 à 5 tokens par batch
            $lastBroadcastTime = microtime(true) * 1000;
            $minBroadcastInterval = 25; // Réduit de 75ms à 25ms pour plus de fluidité

            // Nettoyer tous les buffers existants et désactiver le buffering
            while (ob_get_level() > 0) {
                ob_end_clean();
            }

            // Configuration des headers
            header('Content-Type: text/event-stream');
            header('Cache-Control: no-cache');
            header('X-Accel-Buffering: no');
            header('Connection: keep-alive');

            // Forcer PHP à envoyer les headers maintenant
            flush();

            // Envoyer un ping toutes les 5 secondes pour garder la connexion active
            $lastPingTime = time();

            // 7. Itérer sur le flux et diffuser les chunks progressivement
            foreach ($stream as $response) {
                // Envoyer un ping si nécessaire
                if (time() - $lastPingTime >= 5) {
                    echo ":\n\n"; // Commentaire SSE pour maintenir la connexion
                    // On vérifie et on ignore l'erreur si aucun buffer n'est actif
                    if (ob_get_level() > 0) { @ob_flush(); }
                    flush();
                    $lastPingTime = time();
                }

                $chunk = $response->choices[0]->delta->content ?? '';
                if ($chunk) {
                    $fullResponse .= $chunk;
                    $tokenBuffer .= $chunk;
                    $tokenCount++;
                    $currentTime = microtime(true) * 1000;

                    // Broadcast si on atteint le max de tokens OU si assez de temps s'est écoulé
                    if ($tokenCount >= $maxTokensPerBatch || ($currentTime - $lastBroadcastTime >= $minBroadcastInterval)) {
                        broadcast(new ChatMessageStreamed(
                            channel: $channelName,
                            content: $tokenBuffer,
                            isComplete: false
                        ));

                        $tokenBuffer = '';
                        $tokenCount = 0;
                        $lastBroadcastTime = $currentTime;

                        echo "data: " . json_encode(['status' => 'streaming']) . "\n\n";
                        if (ob_get_level() > 0) { @ob_flush(); }
                        flush();
                    }
                }
            }

            // 8. Diffuser le buffer restant
            if (!empty($buffer)) {
                broadcast(new ChatMessageStreamed(
                    channel: $channelName,
                    content: $buffer,
                    isComplete: false
                ));
            }

            // 9. Mettre à jour le message assistant avec la réponse complète
            $assistantMessage->update([
                'content' => $fullResponse
            ]);

            // 10. Diffuser l'événement final
            broadcast(new ChatMessageStreamed(
                channel: $channelName,
                content: $fullResponse,
                isComplete: true
            ));

            // 11. Gestion intelligente du titre
            $shouldGenerateTitle = $conversation->title === 'Nouvelle conversation' ||
                ($conversation->messages()->count() % 7 === 0); // Régénérer tous les 7 messages

            if ($shouldGenerateTitle) {
                try {
                    // Utiliser les derniers messages comme contexte pour un titre plus pertinent
                    $contextMessages = $conversation->messages()
                        ->orderBy('created_at', 'desc')
                        ->take(7)  // Augmenté à 7 messages pour plus de contexte
                        ->get()
                        ->map(fn($msg) => $msg->content)
                        ->reverse()  // Important : remettre dans l'ordre chronologique
                        ->join("\n");

                    $titleStream = $this->chatService->generateTitle($contextMessages);
                    $titleContent = '';

                    // Stream le titre en temps réel
                    foreach ($titleStream as $response) {
                        $chunk = $response->choices[0]->delta->content ?? '';
                        if ($chunk) {
                            $titleContent .= $chunk;
                            // Diffuser chaque morceau du titre en temps réel
                            broadcast(new ChatMessageStreamed(
                                channel: $channelName,
                                content: $titleContent,
                                isComplete: false,
                                error: false,
                                isTitle: true
                            ));
                        }
                    }

                    // Nettoyer et sauvegarder le titre final
                    $titleContent = trim(str_replace(['"', "'", '.', '!', '?'], '', $titleContent));
                    if (!empty($titleContent)) {
                        $conversation->update([
                            'title' => $titleContent,
                            'last_activity' => now()
                        ]);

                        logger()->info('Titre généré avec succès', [
                            'conversation_id' => $conversation->id,
                            'title' => $titleContent,
                            'messages_count' => $conversation->messages()->count()
                        ]);

                        // Diffuser la version finale du titre
                        broadcast(new ChatMessageStreamed(
                            channel: $channelName,
                            content: $titleContent,
                            isComplete: true,
                            error: false,
                            isTitle: true
                        ));
                    }
                } catch (\Exception $e) {
                    logger()->error('Erreur génération titre:', [
                        'error' => $e->getMessage(),
                        'conversation_id' => $conversation->id
                    ]);
                    $conversation->update(['last_activity' => now()]);
                }
            } else {
                $conversation->update(['last_activity' => now()]);
            }

            return response()->json('ok');

        } catch (\Exception $e) {
            logger()->error('Erreur streamMessage:', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            // Envoyer une réponse d'erreur avec un code 504 pour le timeout
            if (str_contains($e->getMessage(), 'timeout')) {
                return response()->json([
                    'error' => 'Le délai de réponse a été dépassé',
                    'code' => 'TIMEOUT'
                ], 504);
            }

            broadcast(new ChatMessageStreamed(
                channel: "chat.{$conversation->id}",
                content: "Erreur: " . $e->getMessage(),
                isComplete: true,
                error: true
            ));

            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show(Message $message)
    {
        //
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(Message $message)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Message $message)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Message $message)
    {
        //
    }
}
