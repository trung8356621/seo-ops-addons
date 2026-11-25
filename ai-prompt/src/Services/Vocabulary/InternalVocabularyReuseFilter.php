<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services\Vocabulary;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchFoundation\Services\KeywordMetaRepository;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordIntelligence\KeywordSourceNormalizer;

/**
 * Prevent AI → KI Suggest → Internal → AI feedback loops.
 */
final class InternalVocabularyReuseFilter
{
    public function __construct(
        private readonly KeywordSourceNormalizer $sources,
        private readonly KeywordMetaRepository $metaRepository,
    ) {}

    public function isExcludedForInternalReuse(Keyword $keyword, int $siteId): bool
    {
        if ($siteId <= 0) {
            return false;
        }

        $source = $this->sources->normalize(is_string($keyword->source ?? null) ? (string) $keyword->source : null);
        if ($source !== KeywordSourceNormalizer::AI_GENERATED) {
            return false;
        }

        $evidencePrefix = 'site.'.$siteId.'.vocab_evidence.';
        $hasEvidence = $keyword->metas()
            ->where('meta_key', 'like', $evidencePrefix.'%')
            ->exists();

        if (! $hasEvidence) {
            return false;
        }

        $hasNonEvidenceSiteMeta = $keyword->metas()
            ->where('meta_key', 'like', 'site.'.$siteId.'.%')
            ->where('meta_key', 'not like', $evidencePrefix.'%')
            ->exists();

        return ! $hasNonEvidenceSiteMeta;
    }
}