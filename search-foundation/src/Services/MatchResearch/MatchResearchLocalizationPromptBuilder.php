<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services\MatchResearch;

use Omnichannel\Addons\SearchFoundation\DTO\MatchResearch\MatchResearchResource;

final class MatchResearchLocalizationPromptBuilder
{
    public const SCHEMA_VERSION = '1.0';

    public function build(MatchResearchResource $resource, string $targetLocale): string
    {
        $targetLocale = trim($targetLocale);
        if ($targetLocale === '' || $targetLocale === $resource->sourceLocale) {
            throw new \InvalidArgumentException('target_locale must differ from source_locale.');
        }

        $contract = [
            'schema_version' => self::SCHEMA_VERSION,
            'resource_key' => $resource->key,
            'origin' => $resource->origin->value,
            'kind' => $resource->kind->value,
            'source_locale' => $resource->sourceLocale,
            'target_locale' => $targetLocale,
            'source_payload' => $this->exportablePayload($resource),
            'output_schema' => [
                'type' => 'object',
                'required' => ['schema_version', 'resource_key', 'origin', 'kind', 'source_locale', 'target_locale', 'payload'],
                'properties' => [
                    'schema_version' => ['const' => self::SCHEMA_VERSION],
                    'resource_key' => ['const' => $resource->key],
                    'origin' => ['const' => $resource->origin->value],
                    'kind' => ['const' => $resource->kind->value],
                    'source_locale' => ['const' => $resource->sourceLocale],
                    'target_locale' => ['const' => $targetLocale],
                    'payload' => [
                        'type' => 'object',
                        'properties' => [
                            'name' => ['type' => 'string'],
                            'description' => ['type' => 'string'],
                            'aliases' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'positive_examples' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'negative_examples' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'do_not_confuse_with' => ['type' => 'array', 'items' => ['type' => 'string']],
                            'terms' => ['type' => 'array', 'items' => ['type' => 'string']],
                        ],
                    ],
                ],
            ],
            'instructions' => [
                'Preserve the concept meaning; localize for natural search behavior in the target language.',
                'Localize aliases and examples into natural search phrases for the target locale — do not only literal-translate.',
                'Do not blindly translate technical acronyms, model codes, or units (OEM, ODM, MOQ, rPET, 600D, etc.) when they should stay as-is.',
                'Preserve positive vs negative example meaning.',
                'Return JSON only. No markdown fences. No commentary.',
            ],
        ];

        $json = json_encode($contract, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new \RuntimeException('Failed to encode localization prompt contract.');
        }

        return <<<PROMPT
You are localizing a Match & Research resource for search/matching vocabulary.

Follow the contract below exactly. Output a single JSON object matching output_schema.

{$json}
PROMPT;
    }

    /** @return array<string, mixed> */
    private function exportablePayload(MatchResearchResource $resource): array
    {
        $payload = $resource->payload;
        $export = [
            'name' => (string) ($payload['name'] ?? $resource->label),
            'description' => (string) ($payload['description'] ?? $resource->description),
        ];
        foreach (['aliases', 'positive_examples', 'negative_examples', 'do_not_confuse_with', 'terms'] as $field) {
            if (array_key_exists($field, $payload) && is_array($payload[$field])) {
                $export[$field] = array_values(array_filter(array_map('strval', $payload[$field]), static fn (string $v): bool => trim($v) !== ''));
            }
        }

        return $export;
    }
}
