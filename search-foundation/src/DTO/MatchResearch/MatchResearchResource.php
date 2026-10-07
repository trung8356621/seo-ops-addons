<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\DTO\MatchResearch;

use Omnichannel\Addons\SearchFoundation\Enums\MatchResearchKind;
use Omnichannel\Addons\SearchFoundation\Enums\MatchResearchOrigin;

final class MatchResearchResource
{
    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $provenance
     * @param  array<string, array<string, mixed>>  $locales  locale => payload overlay
     */
    public function __construct(
        public readonly string $key,
        public readonly MatchResearchOrigin $origin,
        public readonly MatchResearchKind $kind,
        public readonly string $sourceLocale,
        public readonly string $label,
        public readonly string $description,
        public readonly ?string $matchMode,
        public readonly array $payload,
        public readonly MatchResearchCapabilities $capabilities,
        public readonly bool $editable,
        public readonly bool $deletable,
        public readonly array $provenance = [],
        public readonly array $locales = [],
        public readonly ?int $siteId = null,
        public readonly bool $enabled = true,
    ) {}

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'origin' => $this->origin->value,
            'kind' => $this->kind->value,
            'source_locale' => $this->sourceLocale,
            'label' => $this->label,
            'description' => $this->description,
            'match_mode' => $this->matchMode,
            'payload' => $this->payload,
            'capabilities' => $this->capabilities->toArray(),
            'editable' => $this->editable,
            'deletable' => $this->deletable,
            'provenance' => $this->provenance,
            'locales' => $this->locales,
            'site_id' => $this->siteId,
            'enabled' => $this->enabled,
        ];
    }

    /** @return array<string, mixed> */
    public function localePayload(string $locale): array
    {
        if ($locale === $this->sourceLocale) {
            return $this->payload;
        }

        return $this->locales[$locale] ?? [];
    }
}
