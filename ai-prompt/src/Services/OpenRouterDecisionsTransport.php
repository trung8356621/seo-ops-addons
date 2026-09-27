<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use Illuminate\Support\Facades\Http;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\AiPrompt\Support\DecisionModelIdentityCatalog;
use RuntimeException;

/**
 * OpenRouter Decisions API. Reuses the OpenRouter connection key.
 * Does not call /chat/completions.
 */
final class OpenRouterDecisionsTransport implements DecisionTransport
{
    public const ENDPOINT = 'https://openrouter.ai/api/alpha/decisions';

    public function supports(ApiConnection $connection, string $model): bool
    {
        return strtolower((string) $connection->provider) === ApiConnectionProviders::OPENROUTER
            && trim((string) $connection->api_key) !== ''
            && DecisionModelIdentityCatalog::isOpenRouterJev($model);
    }

    public function endpoint(): string
    {
        return self::ENDPOINT;
    }

    public function submit(ApiConnection $connection, string $model, string $state): array
    {
        if (! $this->supports($connection, $model)) {
            throw new RuntimeException('decision_transport_unavailable');
        }

        $response = Http::timeout(30)
            ->withToken((string) $connection->api_key)
            ->acceptJson()
            ->post(self::ENDPOINT, [
                'model' => trim($model),
                'state' => $state,
                'questions' => self::routingQuestions(),
            ]);

        if (! $response->successful()) {
            throw new RuntimeException('decision_transport_failed');
        }
        $json = $response->json();
        if (! is_array($json)) {
            throw new RuntimeException('decision_transport_failed');
        }

        return $json;
    }

    /**
     * Bounded routing questions. Noul is a probability, not prose.
     * Arbitrary parameter strings are a separate question and are not extracted here.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function routingQuestions(): array
    {
        $noul = static fn (string $instructions): array => [
            'type' => 'noul',
            'instructions' => $instructions,
            'criteria' => [
                'true' => 'The condition holds for this state.',
                'false' => 'The condition does not hold for this state.',
            ],
        ];

        return [
            'need_site' => $noul('Does this turn need the site resource?'),
            'need_keywords' => $noul('Does this turn need the keywords resource?'),
            'need_gsc' => $noul('Does this turn need the GSC resource?'),
            'needs_parameter_extraction' => $noul('Does this turn need a scalar parameter, such as a month or topic ref, that a probability question cannot carry?'),
            'needs_user_confirmation' => $noul('Should the user confirm before a later write?'),
        ];
    }
}
