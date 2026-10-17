<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup;

use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupKeyword;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordGroupSchema;

/**
 * Eligible keyword snapshot for the Python dataset API.
 *
 * Industry evidence is attached only when the caller already has it.
 * This builder does not call concept matching or embedding.
 */
class KeywordGroupDatasetSnapshotBuilder
{
    public function __construct(
        private readonly KeywordGroupCandidateLoader $loader,
        private readonly KeywordGroupingEligibilityGate $eligibility = new KeywordGroupingEligibilityGate(),
    ) {}

    /**
     * @param  list<string>|null  $languageVariants
     * @param  array<string, list<array{key: string, group_type: string}>>  $evidenceByRef
     * @return list<array{ref: string, text: string, language: ?string, industry_evidence: list<array{key: string, group_type: string}>, metadata: array<string, mixed>}>
     */
    public function build(int $siteId, ?string $language, ?array $languageVariants = null, array $evidenceByRef = []): array
    {
        return $this->fromCandidates($siteId, $language, $this->loader->load($siteId, $languageVariants), $evidenceByRef);
    }

    /**
     * @param  list<array{keyword_id: int, phrase: string}>  $keywords
     * @param  array<string, list<array{key: string, group_type: string}>>  $evidenceByRef
     * @return list<array{ref: string, text: string, language: ?string, industry_evidence: list<array{key: string, group_type: string}>, metadata: array<string, mixed>}>
     */
    public function fromCandidates(int $siteId, ?string $language, array $keywords, array $evidenceByRef = []): array
    {
        $language = $this->language($language);
        $protected = $this->protectedKeywordIds($siteId);
        $gateInput = [];
        foreach ($keywords as $row) {
            $keywordId = (int) ($row['keyword_id'] ?? 0);
            $text = KeywordGroupDatasetCanonical::normalizeText((string) ($row['phrase'] ?? ''));
            if ($keywordId <= 0 || $text === '' || isset($protected[$keywordId]) || isset($gateInput[$keywordId])) {
                continue;
            }
            $gateInput[$keywordId] = new KeywordGroupingEligibilityCandidate((string) $keywordId, $text);
        }

        $items = [];
        foreach ($this->eligibility->decide(array_values($gateInput)) as $decision) {
            if (! $decision->eligible) {
                continue;
            }
            $items[] = [
                'ref' => $decision->ref,
                'text' => $decision->text,
                'language' => $language,
                'industry_evidence' => $evidenceByRef[$decision->ref] ?? [],
                'metadata' => [],
            ];
        }
        usort($items, static fn (array $left, array $right): int => strcmp($left['ref'], $right['ref']));

        return $items;
    }

    /**
     * @return array<int, true>
     */
    private function protectedKeywordIds(int $siteId): array
    {
        if ($siteId <= 0 || ! KeywordGroupSchema::tablesReady()) {
            return [];
        }

        $groupIds = [];
        $groups = SeoKeywordGroup::query()->where('site_id', $siteId)->get(['id', 'source', 'is_locked']);
        foreach ($groups as $group) {
            if ($group instanceof SeoKeywordGroup && $group->isProtectedFromSemanticRefresh()) {
                $groupIds[] = (int) $group->id;
            }
        }
        if ($groupIds === []) {
            return [];
        }

        $protected = [];
        $ids = SeoKeywordGroupKeyword::query()
            ->where('site_id', $siteId)
            ->whereIn('group_id', $groupIds)
            ->pluck('keyword_id');
        foreach ($ids as $keywordId) {
            $protected[(int) $keywordId] = true;
        }

        return $protected;
    }

    private function language(?string $language): ?string
    {
        $language = trim((string) $language);

        return $language === '' ? null : mb_substr($language, 0, 32);
    }
}
