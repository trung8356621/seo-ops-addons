<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Reconciliation;

use App\Support\RuntimeLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SiteSync\Services\Contracts\SiteSyncSchema;

/**
 * Idempotent canonical keyword persistence for Site Sync.
 * Finds existing phrase (global unique) — never duplicate-insert.
 * Does not overwrite manual / source_locked rows.
 */
final class CanonicalKeywordReconciler
{
    public function __construct(
        private readonly SiteSyncKeywordCandidateEvaluator $evaluator = new SiteSyncKeywordCandidateEvaluator(),
    ) {}

    /**
     * @param  array{
     *     site_id?: int,
     *     run_id?: int,
     *     user_id?: int,
     *     candidate_type?: string,
     *     raw_value?: string,
     *     href?: string
     * }  $context
     */
    public function findOrAttachEligible(
        string $rawPhrase,
        string $candidateType,
        string $source,
        array $context = [],
    ): ?Keyword {
        $normalized = Keyword::preparePhraseForStorage($rawPhrase);
        $evaluation = $this->evaluator->evaluate($rawPhrase, $normalized, $candidateType);

        if (! $evaluation['eligible'] || $normalized === '') {
            $this->logSkip($context, $candidateType, $rawPhrase, $normalized, $evaluation);

            return null;
        }

        $existing = $this->findByPhrase($normalized);
        if ($existing instanceof Keyword) {
            $this->mergeSourceMetadataIfAllowed($existing, $source);

            return $existing;
        }

        return $this->createCanonical($normalized, $source, $context, $candidateType, $rawPhrase);
    }

    public function findByPhrase(string $phrase): ?Keyword
    {
        $phrase = Keyword::preparePhraseForStorage($phrase);
        if ($phrase === '') {
            return null;
        }

        $exact = Keyword::query()->where('phrase', $phrase)->first();
        if ($exact instanceof Keyword) {
            return $exact;
        }

        $lower = Keyword::query()
            ->whereRaw('LOWER(phrase) = ?', [mb_strtolower($phrase)])
            ->first();

        return $lower instanceof Keyword ? $lower : null;
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $evaluation
     */
    private function logSkip(
        array $context,
        string $candidateType,
        string $raw,
        string $normalized,
        array $evaluation,
    ): void {
        RuntimeLogger::warning('site_sync.keyword_candidate_skipped', [
            'site_id' => (int) ($context['site_id'] ?? 0),
            'run_id' => (int) ($context['run_id'] ?? 0),
            'source' => SiteSyncSchema::SOURCE_PROVIDER,
            'candidate_type' => $candidateType,
            'raw_value' => mb_substr($raw, 0, 200),
            'normalized_value' => mb_substr($normalized, 0, 200),
            'phrase_kind' => $evaluation['phrase_kind'] ?? null,
            'reason' => $evaluation['reason'] ?? null,
            'href' => isset($context['href']) ? mb_substr((string) $context['href'], 0, 200) : null,
        ]);
    }

    private function mergeSourceMetadataIfAllowed(Keyword $existing, string $source): void
    {
        if (! $this->keywordsHaveSourceColumn()) {
            return;
        }

        $existingSource = (string) ($existing->source ?? '');
        $locked = (bool) ($existing->source_locked ?? false);
        if ($locked || $existingSource === SiteSyncSchema::SOURCE_MANUAL) {
            return;
        }

        if ($this->priorityRank($existingSource) < $this->priorityRank($source)) {
            return;
        }

        $next = $source === SiteSyncSchema::SOURCE_WORKSPACE
            ? SiteSyncSchema::SOURCE_WORKSPACE
            : SiteSyncSchema::SOURCE_PROVIDER;

        if ($existingSource === $next) {
            return;
        }

        $existing->forceFill([
            'source' => $next,
            'source_locked' => false,
        ])->save();
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function createCanonical(
        string $phrase,
        string $source,
        array $context,
        string $candidateType,
        string $rawPhrase,
    ): ?Keyword {
        $attrs = [
            'phrase' => $phrase,
            'type' => Keyword::TYPE_NORMAL,
        ];

        if (Schema::connection('omi_seo_ai')->hasColumn('keywords', 'user_id')
            && (int) ($context['user_id'] ?? 0) > 0) {
            $attrs['user_id'] = (int) $context['user_id'];
        }
        if (Schema::connection('omi_seo_ai')->hasColumn('keywords', 'site_id')
            && (int) ($context['site_id'] ?? 0) > 0) {
            $attrs['site_id'] = (int) $context['site_id'];
        }
        if ($this->keywordsHaveSourceColumn()) {
            $attrs['source'] = $source === SiteSyncSchema::SOURCE_WORKSPACE
                ? SiteSyncSchema::SOURCE_WORKSPACE
                : SiteSyncSchema::SOURCE_PROVIDER;
            $attrs['source_locked'] = false;
        }

        try {
            return Keyword::query()->create($attrs);
        } catch (QueryException $e) {
            if (! $this->isUniquePhraseViolation($e)) {
                throw $e;
            }

            $existing = $this->findByPhrase($phrase);
            RuntimeLogger::warning('site_sync.keyword_unique_reconciled', [
                'site_id' => (int) ($context['site_id'] ?? 0),
                'run_id' => (int) ($context['run_id'] ?? 0),
                'source' => $source,
                'candidate_type' => $candidateType,
                'raw_value' => mb_substr($rawPhrase, 0, 200),
                'normalized_value' => mb_substr($phrase, 0, 200),
                'existing_keyword_id' => $existing?->id,
            ]);

            if ($existing instanceof Keyword) {
                $this->mergeSourceMetadataIfAllowed($existing, $source);

                return $existing;
            }

            throw $e;
        }
    }

    private function isUniquePhraseViolation(QueryException $e): bool
    {
        $message = $e->getMessage();

        return str_contains($message, 'keywords_phrase_unique')
            || (str_contains($message, 'Duplicate entry') && str_contains($message, 'phrase'));
    }

    private function priorityRank(string $source): int
    {
        $idx = array_search($source, SiteSyncSchema::KEYWORD_PRIORITY, true);

        return $idx === false ? 99 : (int) $idx;
    }

    private function keywordsHaveSourceColumn(): bool
    {
        try {
            return Schema::connection('omi_seo_ai')->hasColumn('keywords', 'source');
        } catch (\Throwable) {
            return false;
        }
    }
}
