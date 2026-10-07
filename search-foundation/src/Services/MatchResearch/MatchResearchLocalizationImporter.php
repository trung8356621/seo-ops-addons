<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services\MatchResearch;

use Omnichannel\Addons\SearchFoundation\DTO\MatchResearch\MatchResearchResource;
use Omnichannel\Addons\SearchFoundation\Enums\MatchResearchKind;
use Omnichannel\Addons\SearchFoundation\Enums\MatchResearchOrigin;

final class MatchResearchLocalizationImporter
{
    /**
     * @return array{ok:true,payload:array<string,mixed>}|array{ok:false,errors:list<string>}
     */
    public function validateAndNormalize(string $rawJson, MatchResearchResource $resource, string $targetLocale): array
    {
        $targetLocale = trim($targetLocale);
        $errors = [];

        if ($targetLocale === '' || $targetLocale === $resource->sourceLocale) {
            return ['ok' => false, 'errors' => ['target_locale must differ from source_locale; source locale is never mutated by import.']];
        }

        try {
            $decoded = json_decode($this->stripFence($rawJson), true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return ['ok' => false, 'errors' => ['Imported content is not valid JSON.']];
        }

        if (! is_array($decoded) || array_is_list($decoded)) {
            return ['ok' => false, 'errors' => ['Imported JSON must be an object.']];
        }

        if (($decoded['schema_version'] ?? null) !== MatchResearchLocalizationPromptBuilder::SCHEMA_VERSION) {
            $errors[] = 'schema_version must be '.MatchResearchLocalizationPromptBuilder::SCHEMA_VERSION.'.';
        }
        if (($decoded['resource_key'] ?? null) !== $resource->key) {
            $errors[] = 'resource_key does not match the selected resource.';
        }
        if (($decoded['source_locale'] ?? null) !== $resource->sourceLocale) {
            $errors[] = 'source_locale does not match the resource source locale.';
        }
        if (($decoded['target_locale'] ?? null) !== $targetLocale) {
            $errors[] = 'target_locale does not match the requested import locale.';
        }

        $origin = MatchResearchOrigin::tryFrom((string) ($decoded['origin'] ?? ''));
        $kind = MatchResearchKind::tryFrom((string) ($decoded['kind'] ?? ''));
        if ($origin !== $resource->origin) {
            $errors[] = 'origin is incompatible with the selected resource.';
        }
        if ($kind !== $resource->kind) {
            $errors[] = 'kind is incompatible with the selected resource.';
        }

        $payload = $decoded['payload'] ?? null;
        if (! is_array($payload) || array_is_list($payload)) {
            $errors[] = 'payload must be an object.';
            $payload = [];
        }

        if ($errors !== []) {
            return ['ok' => false, 'errors' => $errors];
        }

        return ['ok' => true, 'payload' => $this->normalizePayload($payload, $resource->kind)];
    }

    /** @param array<string, mixed> $payload @return array<string, mixed> */
    private function normalizePayload(array $payload, MatchResearchKind $kind): array
    {
        $out = [
            'name' => trim((string) ($payload['name'] ?? '')),
            'description' => trim((string) ($payload['description'] ?? '')),
            'aliases' => $this->normalizeStringList($payload['aliases'] ?? []),
            'positive_examples' => $this->normalizeStringList($payload['positive_examples'] ?? []),
            'negative_examples' => $this->normalizeStringList($payload['negative_examples'] ?? []),
        ];

        if ($kind === MatchResearchKind::Ambiguity) {
            $out['do_not_confuse_with'] = $this->normalizeStringList($payload['do_not_confuse_with'] ?? []);
        }
        if ($kind === MatchResearchKind::RuleSet) {
            $out['terms'] = $this->normalizeStringList($payload['terms'] ?? $payload['aliases'] ?? []);
        }

        return $out;
    }

    /** @return list<string> */
    private function normalizeStringList(mixed $raw): array
    {
        if (! is_array($raw)) {
            return [];
        }
        $out = [];
        $seen = [];
        foreach ($raw as $item) {
            $value = trim((string) $item);
            if ($value === '') {
                continue;
            }
            $dedupe = mb_strtolower($value, 'UTF-8');
            if (isset($seen[$dedupe])) {
                continue;
            }
            $seen[$dedupe] = true;
            $out[] = $value;
        }

        return $out;
    }

    private function stripFence(string $raw): string
    {
        $trim = trim($raw);
        if (preg_match('/^```(?:json)?\s*(.*?)\s*```$/s', $trim, $m) === 1) {
            return trim($m[1]);
        }

        return $trim;
    }
}
