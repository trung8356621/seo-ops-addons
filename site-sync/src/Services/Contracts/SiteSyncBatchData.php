<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Contracts;

/**
 * SiteSyncBatch envelope (snapshot|delta).
 */
final readonly class SiteSyncBatchData
{
    /**
     * @param  array<string, mixed>|null  $profile
     * @param  list<array<string, mixed>>  $articles
     * @param  list<array<string, mixed>>  $links
     * @param  list<array<string, mixed>>  $providerKeywords
     * @param  list<array<string, mixed>>  $scores
     * @param  list<array<string, mixed>>  $contactsSuggest
     * @param  array<string, mixed>|null  $capabilityRef
     */
    public function __construct(
        public string $schema,
        public string $mode,
        public ?string $runToken,
        public ?int $siteIdHint,
        public ?string $cursor,
        public bool $hasMore,
        public ?array $profile,
        public array $articles,
        public array $links,
        public array $providerKeywords,
        public array $scores,
        public array $contactsSuggest,
        public ?array $capabilityRef,
        public array $raw = [],
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        $schema = (string) ($payload['schema'] ?? SiteSyncSchema::VERSION);
        if (! SiteSyncSchema::isSupportedSchema($schema)) {
            throw new \InvalidArgumentException('Unsupported site sync batch schema: '.$schema);
        }

        $mode = (string) ($payload['mode'] ?? SiteSyncSchema::MODE_DELTA);
        if (! in_array($mode, [
            SiteSyncSchema::MODE_SNAPSHOT,
            SiteSyncSchema::MODE_DELTA,
            SiteSyncSchema::MODE_FORCE_FULL,
        ], true)) {
            throw new \InvalidArgumentException('Invalid site sync mode: '.$mode);
        }

        $siteIdHint = $payload['site_id_hint'] ?? null;

        return new self(
            schema: $schema,
            mode: $mode,
            runToken: isset($payload['run_token']) ? (string) $payload['run_token'] : null,
            siteIdHint: is_numeric($siteIdHint) ? (int) $siteIdHint : null,
            cursor: isset($payload['cursor']) ? (string) $payload['cursor'] : null,
            hasMore: (bool) ($payload['has_more'] ?? false),
            profile: is_array($payload['profile'] ?? null) ? $payload['profile'] : null,
            articles: self::listOfArrays($payload['articles'] ?? []),
            links: self::listOfArrays($payload['links'] ?? []),
            providerKeywords: self::listOfArrays($payload['provider_keywords'] ?? []),
            scores: self::listOfArrays($payload['scores'] ?? []),
            contactsSuggest: self::listOfArrays($payload['contacts_suggest'] ?? []),
            capabilityRef: is_array($payload['capability_ref'] ?? null) ? $payload['capability_ref'] : null,
            raw: $payload,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'schema' => $this->schema,
            'mode' => $this->mode,
            'run_token' => $this->runToken,
            'site_id_hint' => $this->siteIdHint,
            'cursor' => $this->cursor,
            'has_more' => $this->hasMore,
            'profile' => $this->profile,
            'articles' => $this->articles,
            'links' => $this->links,
            'provider_keywords' => $this->providerKeywords,
            'scores' => $this->scores,
            'contacts_suggest' => $this->contactsSuggest,
            'capability_ref' => $this->capabilityRef,
        ];
    }

    /**
     * @param  mixed  $value
     * @return list<array<string, mixed>>
     */
    private static function listOfArrays(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        $out = [];
        foreach ($value as $row) {
            if (is_array($row)) {
                $out[] = $row;
            }
        }

        return $out;
    }
}
