<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use App\Models\CustomInstruction;
use OpenAI\Client;
use Illuminate\Support\Facades\Auth;

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

    protected function getSystemMessage(): array
    {
        return [
            'role' => 'system',
            'content' => <<<EOT
Tu es un assistant IA expert et professionnel. Voici tes instructions principales :

RÈGLES DE FORMATAGE :
- Utilise le Markdown pour formater tes réponses
- Utilise des blocs de code avec highlighting pour le code (`\`\`\`language`)
- Mets en gras les points importants
- Utilise des listes numérotées pour les étapes
- Utilise des tableaux Markdown quand c'est pertinent

STYLE DE RÉPONSE :
- Sois direct et concis tout en restant professionnel
- Structure tes réponses de manière claire
- Pour du code, ajoute toujours des commentaires explicatifs
- Fournis des exemples concrets quand c'est pertinent
- Si pertinent, termine par une courte conclusion ou des bonnes pratiques

CONTRAINTES :
- Ne fournis jamais de code malveillant
- Vérifie toujours les implications de sécurité
- En cas de doute, demande des précisions
- Reste factuel et précis

Adapte ton niveau de détail selon la complexité de la question.
EOT
        ];
    }

    protected function getSystemMessageForTitle(): array
    {
        return [
            'role' => 'system',
            'content' => <<<EOT
Tu es un expert en création de titres courts et précis.
Ta tâche est de générer un titre qui capture l'essence de la conversation.

RÈGLES STRICTES:
- Le titre DOIT contenir entre 4 et 8 mots exactement
- Maximum 60 caractères au total
- Pas de caractères spéciaux ni de ponctuation
- Pas de guillemets
- Commence par une majuscule
- Ne termine pas par un point
- Évite les articles (le, la, les, un, une) sauf si nécessaire
- Évite les mots génériques (discussion, conversation, échange)

EXEMPLES DE BONS TITRES:
- Développement Application Laravel avec VueJS
- Optimisation Performance Base de Données
- Integration Système Paiement Stripe API

EXEMPLES À ÉVITER:
- Nouveau défi (trop court)
- Discussion développement (trop générique)
- La conversation à propos du développement Laravel (trop long)
EOT
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
                'messages_count' => count($messages)
            ]);

            // Vérifier le dernier message utilisateur
            $lastUserMessage = collect($messages)->last();
            if ($lastUserMessage && $lastUserMessage['role'] === 'user') {
                $content = trim($lastUserMessage['content']);

                // Si le message est trop court, on l'enrichit
                if (strlen($content) < 10) {
                    $lastUserMessage['content'] = "Pourrais-tu répondre de manière détaillée à ceci: $content";
                    $messages[array_key_last($messages)] = $lastUserMessage;
                }
            }

            $models = collect($this->getModels());
            if (!$model || !$models->contains('id', $model)) {
                $model = self::DEFAULT_MODEL;
            }

            // Ajout du prompt système au début des messages
            $messages = [$this->getChatSystemPrompt(), ...$messages];

            try {
                $stream = $this->client->chat()->createStreamed([
                    'model' => $model,
                    'messages' => $messages,
                    'temperature' => 0.7, // Valeur ChatGPT par défaut
                    'max_tokens' => 2048, // Similaire à ChatGPT
                    'presence_penalty' => 0.5, // Valeur ChatGPT
                    'frequency_penalty' => 0.5, // Valeur ChatGPT
                    'stream' => true,
                    'top_p' => 1, // Valeur ChatGPT
                ]);

                if (!$stream) {
                    throw new \Exception("Le provider n'a pas retourné de réponse valide");
                }

                // Test du premier chunk pour valider la réponse
                $iterator = $stream->getIterator();
                $iterator->rewind();
                if (!$iterator->valid()) {
                    throw new \Exception("Le stream initial est invalide");
                }

                return $stream;

            } catch (\OpenAI\Exceptions\ErrorException $e) {
                logger()->error('Erreur OpenRouter:', [
                    'message' => $e->getMessage(),
                    'code' => $e->getCode(),
                    'model' => $model,
                    'last_message' => $lastUserMessage['content'] ?? null
                ]);

                if (str_contains(strtolower($e->getMessage()), 'provider')) {
                    throw new \Exception("Le modèle est temporairement indisponible, essayez un autre modèle.");
                }

                throw $e;
            }

        } catch (\Exception $e) {
            logger()->error('Erreur critique dans streamConversation:', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    private function enrichShortMessage(string $message): string
    {
        $message = trim($message);
        $commonGreetings = ['bonjour', 'salut', 'hey', 'hello', 'hi', 'coucou'];

        if (strlen($message) < 10) {
            foreach ($commonGreetings as $greeting) {
                if (stripos($message, $greeting) !== false) {
                    return "Le message initial est une salutation: '$message'. En tant qu'assistant, je vais vous aider tout au long de nos échanges. Je suis là pour répondre à vos questions et vous assister dans vos tâches.";
                }
            }
            return "Le message initial est court: '$message'. En tant qu'assistant, je vais analyser votre demande et vous fournir une réponse appropriée.";
        }
        return $message;
    }

    public function generateTitle(string $messages): mixed
    {
        // Enrichir le contexte pour les premiers messages courts
        $messagesArray = explode("\n", $messages);
        if (count($messagesArray) <= 2) {
            $messages = $this->enrichShortMessage($messages);
        }

        $prompt = <<<EOT
Analyse cette conversation et génère un titre professionnel qui respecte ces règles:

RÈGLES OBLIGATOIRES:
- Entre 4 et 6 mots exactement
- Pas d'articles inutiles
- Pas de ponctuation
- Style professionnel et technique
- Capture le sujet principal

Conversation:
$messages

Génère uniquement le titre, sans autre texte ni explications.
EOT;

        return $this->streamConversation(
            messages: [[
                'role' => 'user',
                'content' => $prompt
            ]],
            model: self::DEFAULT_MODEL,
            temperature: 0.5 // Réduit pour des titres plus consistants comme ChatGPT
        );
    }

}
