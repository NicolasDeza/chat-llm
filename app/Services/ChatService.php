<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\CustomInstruction;

class ChatService
{
    private $baseUrl;
    private $apiKey;
    private $client;
    public const DEFAULT_MODEL = 'meta-llama/llama-3.2-11b-vision-instruct:free';

    public function __construct()
    {
        $this->baseUrl = config('services.openrouter.base_url', 'https://openrouter.ai/api/v1');
        $this->apiKey = config('services.openrouter.api_key');
        $this->client = $this->createOpenAIClient();
    }

    /**
     * @return array<array-key, array{
     *     id: string,
     *     name: string,
     *     context_length: int,
     *     max_completion_tokens: int,
     *     pricing: array{prompt: int, completion: int}
     * }>
     */
    public function getModels(): array
    {
        return cache()->remember('openai.models', now()->addHour(), function () {
            $response = Http::withHeaders([
                'Authorization' => 'Bearer ' . $this->apiKey,
            ])->get($this->baseUrl . '/models');

            return collect($response->json()['data'])
                ->filter(function ($model) {
                    // Filtrer les éléments nuls et s'assurer que 'id' existe
                    return $model !== null && isset($model['id']) && str_ends_with($model['id'], ':free');
                })
                ->sortBy(function ($model) {
                    return $model['name'] ?? '';
                })
                ->map(function ($model) {
                    return [
                        'id' => $model['id'] ?? '',
                        'name' => $model['name'] ?? '',
                        'context_length' => $model['context_length'] ?? 0,
                        'max_completion_tokens' => isset($model['top_provider']['max_completion_tokens']) ? $model['top_provider']['max_completion_tokens'] : 0,
                        'pricing' => $model['pricing'] ?? [],
                    ];
                })
                ->values()
                ->all();
        });
    }

    /**
     * @param array{role: 'user'|'assistant'|'system'|'function', content: string} $messages
     * @param string|null $model
     * @param float $temperature
     *
     * @return string
     */


    private function createOpenAIClient(): \OpenAI\Client
    {
        return \OpenAI::factory()
            ->withApiKey($this->apiKey)
            ->withBaseUri($this->baseUrl)
            ->withHttpClient(new \GuzzleHttp\Client([
                'timeout' => 120,
                'connect_timeout' => 120
            ]))
            ->make();
    }

    /**
     * @return array{role: 'system', content: string}
     */
    private function getChatSystemPrompt(): array
    {
        $user = auth()->user();
        $now = now()->locale('fr')->format('l d F Y H:i');

        // Log des informations de l'utilisateur
        logger()->info('Information utilisateur:', [
            'user_id' => $user->id,
            'user_name' => $user->name
        ]);

        // Récupérer et logger les instructions personnalisées
        $customInstruction = CustomInstruction::where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        logger()->info('Instructions personnalisées:', [
            'has_instructions' => !is_null($customInstruction),
            'instruction_data' => $customInstruction
        ]);

        // Récupérer les instructions personnalisées actives
        $customInstruction = CustomInstruction::where('user_id', $user->id)
            ->where('is_active', true)
            ->first();

        $systemPrompt = "Tu es un assistant de chat. La date et l'heure actuelle est le {$now}.\n";
        $systemPrompt .= "Tu es actuellement utilisé par {$user->name}.\n";

        if ($customInstruction) {
            if ($customInstruction->about_user) {
                $systemPrompt .= "\nÀ propos de l'utilisateur:\n" . $customInstruction->about_user;
            }
            if ($customInstruction->preference) {
                $systemPrompt .= "\nPréférences de réponse:\n" . $customInstruction->preference;
            }
        }

        return [
            'role' => 'system',
            'content' => $systemPrompt,
        ];
    }

    /**
     * Diffuse un flux de réponse en streaming depuis l'API.
     *
     * @param array $messages
     * @param string|null $model
     * @param float $temperature
     * @return iterable
     */
    public function streamConversation(array $messages, ?string $model = null, float $temperature = 0.7): iterable
    {
        try {
            logger()->info('Début streamConversation', [
                'model' => $model,
                'temperature' => $temperature,
            ]);

            $models = collect($this->getModels());
            if (!$model || !$models->contains('id', $model)) {
                $model = self::DEFAULT_MODEL;
            }

            // Ajout du prompt système au début des messages
            $messages = [$this->getChatSystemPrompt(), ...$messages];

            // Ajout de try/catch spécifique pour les erreurs OpenRouter
            try {
                $stream = $this->client->chat()->createStreamed([
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => $temperature,
                ]);

                // Vérifier si le stream est valide
                if (!$stream) {
                    throw new \Exception("Erreur: Le service n'a pas retourné de réponse valide");
                }

                return $stream;

            } catch (\OpenAI\Exceptions\ErrorException $e) {
                logger()->error('Erreur OpenRouter:', [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'model' => $model
                ]);

                // Messages d'erreur plus précis
                if (str_contains(strtolower($e->getMessage()), 'rate limit')) {
                    throw new \Exception("Quota d'appels API dépassé. Veuillez réessayer dans quelques minutes.");
                }
                if (str_contains(strtolower($e->getMessage()), 'invalid')) {
                    throw new \Exception("Configuration API invalide. Contactez l'administrateur.");
                }

                throw new \Exception("Le service OpenRouter est temporairement indisponible. Veuillez réessayer.");
            }

        } catch (\Exception $e) {
            logger()->error('Erreur dans streamConversation:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    public function generateTitle(string $messages): mixed
    {
        return $this->streamConversation(
            messages: [[
                'role' => 'user',
                'content' => "Je souhaite que tu génères un titre court et percutant, contenant au maximum 4 mots, qui résume avec précision l’échange suivant :\n\n$messages\n\nLe titre doit être concis et direct, sans phrase complète ni texte additionnel. Si l’échange est incohérent, illisible ou trop bref pour être résumé, ta seule réponse doit être : 'Demande de clarification'. Aucune autre information ne doit être ajoutée, même si cela semble pertinent. Si la conversation est trop complexe ou trop longue, réponds simplement 'Résumé de la discussion'."
            ]],
            model: self::DEFAULT_MODEL
        );
    }

}
