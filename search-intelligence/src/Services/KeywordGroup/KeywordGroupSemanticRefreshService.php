<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Omnichannel\Addons\SearchFoundation\Contracts\IndustryContextKeyResolver;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordGroup\KeywordGroupSource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupAnalysisRun;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\IndustryGroup\IndustryGroupMatchEntity;
use Omnichannel\Addons\SearchIntelligence\Services\IndustryGroup\IndustryGroupSemanticMatcher;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticAnalyticsClient;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticHttpException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticInvalidResponseException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticTransportException;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\TopicGroupingInputHasher;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordGroupSchema;

/**
 * Semantic keyword grouping stops at Group persistence.
 *
 * Does not call Topic apply, recluster, DNA, or Topic membership writes.
 * Manual Groups (source=manual) and locked Groups are kept exactly.
 */
final class KeywordGroupSemanticRefreshService
{
    public function __construct(
        private readonly SemanticAnalyticsClient $client,
        private readonly TopicGroupingInputHasher $hasher,
        private readonly KeywordGroupCandidateLoader $loader,
        private readonly KeywordGroupingEligibilityGate $eligibility = new KeywordGroupingEligibilityGate(),
        private readonly ?IndustryContextKeyResolver $industryKeys = null,
        private readonly ?IndustryGroupSemanticMatcher $industryMatcher = null,
    ) {}

    /**
     * @param  list<string>|null  $languageVariants
     */
    public function refreshSite(int $siteId, ?string $language, ?array $languageVariants = null): KeywordGroupRefreshResult
    {
        return $this->refreshKeywords($siteId, $language, $this->loader->load($siteId, $languageVariants));
    }

    /**
     * @param  list<array{keyword_id: int, phrase: string}>  $keywords
     */
    public function refreshKeywords(int $siteId, ?string $language, array $keywords): KeywordGroupRefreshResult
    {
        if ($siteId <= 0 || ! KeywordGroupSchema::tablesReady()) {
            return new KeywordGroupRefreshResult(0, 0, 0, true);
        }

        [$protectedGroupIds, $protectedKeywords] = $this->protectedState($siteId);
        $gateInput = [];
        foreach ($keywords as $row) {
            $keywordId = (int) ($row['keyword_id'] ?? 0);
            $text = $this->hasher->normalizeText((string) ($row['phrase'] ?? ''));
            if ($keywordId <= 0 || $text === '' || isset($protectedKeywords[$keywordId]) || isset($gateInput[$keywordId])) {
                continue;
            }
            $gateInput[$keywordId] = new KeywordGroupingEligibilityCandidate((string) $keywordId, $text);
        }

        $decisions = $this->eligibility->decide(array_values($gateInput));
        $payload = [];
        $known = [];
        $excludedByReason = [];
        foreach ($decisions as $decision) {
            if (! $decision->eligible) {
                foreach ($decision->excludeReasons as $reason) {
                    $excludedByReason[$reason] = ($excludedByReason[$reason] ?? 0) + 1;
                }

                continue;
            }
            $keywordId = (int) $decision->ref;
            $known[$keywordId] = true;
            $payload[] = ['ref' => $decision->ref, 'text' => $decision->text];
        }

        $candidateCount = count($decisions);
        $eligibleCount = count($payload);
        $excludedCount = $candidateCount - $eligibleCount;
        $industry = $this->industryEvidence($siteId, $language, $payload);

        $language = $this->normalizeLanguage($language);
        if ($payload === []) {
            $unknownLanguageGroups = $this->clearGroupsOwnedByLanguage($siteId, $language);

            return new KeywordGroupRefreshResult(
                0,
                0,
                count($protectedGroupIds),
                true,
                $candidateCount,
                0,
                $excludedCount,
                $excludedByReason,
                $industry['status'],
                $industry['keyword_count'],
                $industry['membership_count'],
                $unknownLanguageGroups,
            );
        }

        $scopeRef = (string) $siteId;
        $response = $this->requestGlobalAnalysis($scopeRef, $language, $payload, $industry['evidence']);

        $parsed = $this->parseResponse($response, $known);
        $written = $this->replaceSemanticGroups($siteId, $parsed['groups'], $parsed['input_hash'], $parsed['algorithm'], $protectedKeywords, array_keys($known), $language);

        return new KeywordGroupRefreshResult(
            $written['groups'],
            $written['members'],
            count($protectedGroupIds),
            false,
            $candidateCount,
            $eligibleCount,
            $excludedCount,
            $excludedByReason,
            $industry['status'],
            $industry['keyword_count'],
            $industry['membership_count'],
        );
    }

    /**
     * Publish one completed Python run. The caller must already have checked
     * dataset freshness. This method does not call Python.
     *
     * @param  array<string, mixed>  $response
     * @param  array<int, true>  $knownKeywordIds
     * @return array{groups: int, members: int}
     */
    public function publishAnalysisResponse(int $siteId, array $response, array $knownKeywordIds, ?string $language = null): array
    {
        $parsed = $this->parseResponse($response, $knownKeywordIds);
        [, $protectedKeywords] = $this->protectedState($siteId);

        return $this->replaceSemanticGroups(
            $siteId,
            $parsed['groups'],
            $parsed['input_hash'],
            $parsed['algorithm'],
            $protectedKeywords,
            array_keys($knownKeywordIds),
            $this->normalizeLanguage($language),
        );
    }

    /**
     * Small sets use one analysis call. Larger sets embed under the Python
     * request cap, then cluster once from the shared embedding cache so
     * keywords from different batches can join the same group.
     * Persistence happens only after this returns.
     *
     * @param  list<array{ref: string, text: string}>  $payload
     * @param  list<array{ref: string, memberships: list<array{key: string, group_type: string}>}>  $industryEvidence
     * @return array<string, mixed>
     */
    private function requestGlobalAnalysis(string $scopeRef, ?string $language, array $payload, array $industryEvidence): array
    {
        $body = [
            'scope_ref' => $scopeRef,
            'language' => $language,
            'keywords' => $payload,
        ];
        if ($industryEvidence !== []) {
            $body['industry_evidence'] = $industryEvidence;
        }

        $batchSize = $this->embeddingBatchSize();
        if (count($payload) <= $batchSize) {
            return $this->client->postJson('/v1/keyword-groups/analyses', $body, 'keyword-group-'.$scopeRef);
        }

        $chunks = array_chunk($payload, $batchSize);
        $total = count($chunks);
        foreach ($chunks as $index => $chunk) {
            try {
                $this->client->postJson('/v1/keyword-groups/embedding-batches', [
                    'scope_ref' => $scopeRef,
                    'language' => $language,
                    'keywords' => array_values($chunk),
                ], 'keyword-group-'.$scopeRef.'-embed-'.($index + 1));
            } catch (SemanticHttpException $e) {
                throw new SemanticTransportException(
                    'keyword group embedding batch '.($index + 1).'/'.$total.' failed: '.$e->getMessage(),
                    $e->errorCode,
                    $e->httpStatus,
                    $e,
                );
            }
        }

        try {
            return $this->client->postJson(
                '/v1/keyword-groups/analyses/from-cache',
                $body,
                'keyword-group-'.$scopeRef.'-cluster',
                $this->clusterTimeoutSeconds(),
            );
        } catch (SemanticHttpException $e) {
            throw new SemanticTransportException(
                'keyword group clustering failed: '.$e->getMessage(),
                $e->errorCode,
                $e->httpStatus,
                $e,
            );
        }
    }

    private function embeddingBatchSize(): int
    {
        $size = (int) config('semantic.keyword_group_batch_size', 1000);

        return max(1, min(2000, $size));
    }

    private function clusterTimeoutSeconds(): int
    {
        return max(120, (int) config('semantic.keyword_group_cluster_timeout', 1800));
    }

    /**
     * @return array{0: list<int>, 1: array<int, true>}
     */
    private function protectedState(int $siteId): array
    {
        $groups = SeoKeywordGroup::query()
            ->where('site_id', $siteId)
            ->get(['id', 'source', 'is_locked']);
        $protectedGroupIds = [];
        foreach ($groups as $group) {
            if ($group instanceof SeoKeywordGroup && $group->isProtectedFromSemanticRefresh()) {
                $protectedGroupIds[] = (int) $group->id;
            }
        }

        $protectedKeywords = [];
        if ($protectedGroupIds !== []) {
            $ids = SeoKeywordGroupKeyword::query()
                ->where('site_id', $siteId)
                ->whereIn('group_id', $protectedGroupIds)
                ->pluck('keyword_id');
            foreach ($ids as $keywordId) {
                $protectedKeywords[(int) $keywordId] = true;
            }
        }

        return [$protectedGroupIds, $protectedKeywords];
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<int, true>  $knownKeywordIds
     * @return array{
     *     input_hash: ?string,
     *     algorithm: ?string,
     *     groups: list<array{ref: string, name: string, representative_keyword_id: int, members: list<array{keyword_id: int, similarity_score: ?float}>}>
     * }
     */
    private function parseResponse(array $response, array $knownKeywordIds): array
    {
        $status = (string) ($response['status'] ?? '');
        if ($status === 'failed') {
            throw SemanticInvalidResponseException::contract((string) ($response['error'] ?? 'semantic_analysis_failed'));
        }
        if ($status !== 'completed') {
            throw SemanticInvalidResponseException::contract('unexpected status: '.$status);
        }

        $rawGroups = $response['groups'] ?? null;
        if (! is_array($rawGroups)) {
            throw SemanticInvalidResponseException::contract('groups must be an array');
        }

        $seen = [];
        $groups = [];
        foreach ($rawGroups as $index => $rawGroup) {
            if (! is_array($rawGroup)) {
                throw SemanticInvalidResponseException::contract('group['.$index.'] must be an object');
            }
            $groups[] = $this->parseGroup($rawGroup, $knownKeywordIds, $seen, $index);
        }

        $diagnostics = is_array($response['diagnostics'] ?? null) ? $response['diagnostics'] : [];
        $algorithm = trim((string) ($diagnostics['algorithm'] ?? ''));
        $inputHash = trim((string) ($response['input_hash'] ?? ''));

        return [
            'input_hash' => $inputHash === '' ? null : mb_substr($inputHash, 0, 64),
            'algorithm' => $algorithm === '' ? null : mb_substr($algorithm, 0, 64),
            'groups' => $groups,
        ];
    }

    /**
     * @param  array<string, mixed>  $rawGroup
     * @param  array<int, true>  $knownKeywordIds
     * @param  array<int, true>  $seen
     * @return array{ref: string, name: string, representative_keyword_id: int, members: list<array{keyword_id: int, similarity_score: ?float}>}
     */
    private function parseGroup(array $rawGroup, array $knownKeywordIds, array &$seen, int $index): array
    {
        $ref = trim((string) ($rawGroup['group_ref'] ?? ''));
        $name = trim((string) ($rawGroup['representative_text'] ?? ''));
        $membersRaw = $rawGroup['members'] ?? null;
        if ($ref === '' || $name === '' || ! is_array($membersRaw) || $membersRaw === []) {
            throw SemanticInvalidResponseException::contract('malformed group at index '.$index);
        }

        $members = [];
        foreach ($membersRaw as $memberIndex => $memberRaw) {
            if (! is_array($memberRaw)) {
                throw SemanticInvalidResponseException::contract(
                    'group['.$index.'].members['.$memberIndex.'] must be an object',
                );
            }
            $keywordId = (int) ($memberRaw['ref'] ?? 0);
            if ($keywordId <= 0 || ! isset($knownKeywordIds[$keywordId])) {
                throw SemanticInvalidResponseException::contract('unknown keyword ref in group '.$index);
            }
            if (isset($seen[$keywordId])) {
                throw SemanticInvalidResponseException::contract('duplicate keyword ref: '.$keywordId);
            }
            $seen[$keywordId] = true;
            $score = isset($memberRaw['similarity_score']) ? (float) $memberRaw['similarity_score'] : null;
            $members[] = [
                'keyword_id' => $keywordId,
                'similarity_score' => $score,
            ];
        }

        $representativeId = (int) ($rawGroup['representative_ref'] ?? 0);
        $memberIds = array_column($members, 'keyword_id');
        if (! in_array($representativeId, $memberIds, true)) {
            $representativeId = (int) $memberIds[0];
        }

        return [
            'ref' => mb_substr($ref, 0, 128),
            'name' => mb_substr($name, 0, 255),
            'representative_keyword_id' => $representativeId,
            'members' => $members,
        ];
    }

    /**
     * @param  list<array{ref: string, name: string, representative_keyword_id: int, members: list<array{keyword_id: int, similarity_score: ?float}>}>  $groups
     * @param  array<int, true>  $protectedKeywords
     * @param  list<int>  $scopeKeywordIds
     * @return array{groups: int, members: int}
     */
    private function replaceSemanticGroups(
        int $siteId,
        array $groups,
        ?string $inputHash,
        ?string $algorithm,
        array $protectedKeywords,
        array $scopeKeywordIds,
        ?string $language = null,
    ): array {
        return DB::connection('omi_seo_ai')->transaction(function () use ($siteId, $groups, $inputHash, $algorithm, $protectedKeywords, $scopeKeywordIds, $language): array {
            if ($groups === [] && $scopeKeywordIds === []) {
                return ['groups' => 0, 'members' => 0];
            }

            $membershipLanguage = $this->storedMembershipLanguage($language);
            $foreignHashes = $this->foreignDatasetHashes($siteId, $language);
            $foreignGroupIds = $foreignHashes === []
                ? []
                : SeoKeywordGroup::query()
                    ->where('site_id', $siteId)
                    ->where('source', KeywordGroupSource::SEMANTIC)
                    ->whereIn('input_hash', $foreignHashes)
                    ->pluck('id')
                    ->map(static fn ($id): int => (int) $id)
                    ->all();
            if ($membershipLanguage === null && $foreignGroupIds !== []) {
                $foreignKeywordIds = SeoKeywordGroupKeyword::query()
                    ->where('site_id', $siteId)
                    ->whereIn('group_id', $foreignGroupIds)
                    ->pluck('keyword_id');
                foreach ($foreignKeywordIds as $keywordId) {
                    $protectedKeywords[(int) $keywordId] = true;
                }
            }

            $protectedGroupIds = SeoKeywordGroup::query()
                ->where('site_id', $siteId)
                ->get()
                ->filter(static fn (SeoKeywordGroup $group): bool => $group->isProtectedFromSemanticRefresh())
                ->map(static fn (SeoKeywordGroup $group): int => (int) $group->id)
                ->all();
            $protectedGroupIds = array_values(array_unique(array_merge($protectedGroupIds, $foreignGroupIds)));

            foreach (array_chunk($scopeKeywordIds, 500) as $chunk) {
                $memberDelete = SeoKeywordGroupKeyword::query()
                    ->where('site_id', $siteId)
                    ->whereIn('keyword_id', $chunk);
                if ($membershipLanguage !== null) {
                    $memberDelete->where('language', $membershipLanguage);
                }
                if ($protectedGroupIds !== []) {
                    $memberDelete->whereNotIn('group_id', $protectedGroupIds);
                }
                $memberDelete->delete();
            }

            $emptyGroups = SeoKeywordGroup::query()
                ->where('site_id', $siteId)
                ->where('source', KeywordGroupSource::SEMANTIC)
                ->where('is_locked', false)
                ->whereDoesntHave('memberships');
            if ($protectedGroupIds !== []) {
                $emptyGroups->whereNotIn('id', $protectedGroupIds);
            }
            if ($foreignHashes !== []) {
                $emptyGroups->where(function ($query) use ($foreignHashes): void {
                    $query->whereNull('input_hash')
                        ->orWhere('input_hash', '')
                        ->orWhereNotIn('input_hash', $foreignHashes);
                });
            }
            $emptyIds = $emptyGroups->pluck('id');
            if ($emptyIds->isNotEmpty()) {
                SeoKeywordGroup::query()->whereIn('id', $emptyIds->all())->delete();
            }

            $writtenGroups = 0;
            $writtenMembers = 0;
            foreach ($groups as $group) {
                $members = [];
                foreach ($group['members'] as $member) {
                    if (isset($protectedKeywords[$member['keyword_id']])) {
                        continue;
                    }
                    $members[] = $member;
                }
                if ($members === []) {
                    continue;
                }
                $writtenGroups++;
                $writtenMembers += count($members);

                $representativeId = (int) $group['representative_keyword_id'];
                $memberIds = array_column($members, 'keyword_id');
                if (! in_array($representativeId, $memberIds, true)) {
                    $representativeId = (int) $memberIds[0];
                }

                $created = SeoKeywordGroup::query()->create([
                    'site_id' => $siteId,
                    'name' => $group['name'],
                    'source' => KeywordGroupSource::SEMANTIC,
                    'representative_keyword_id' => $representativeId,
                    'semantic_group_ref' => $group['ref'],
                    'algorithm' => $algorithm,
                    'input_hash' => $inputHash,
                    'is_locked' => false,
                ]);

                foreach ($members as $member) {
                    $payload = [
                        'site_id' => $siteId,
                        'group_id' => $created->id,
                        'keyword_id' => $member['keyword_id'],
                        'source' => KeywordGroupSource::SEMANTIC,
                        'similarity_score' => $member['similarity_score'],
                    ];
                    if ($membershipLanguage !== null) {
                        $payload['language'] = $membershipLanguage;
                    }
                    if (\Omnichannel\Addons\SearchIntelligence\Support\KeywordGroupSchema::topicCandidateReady()) {
                        $payload['is_topic_candidate'] = true;
                    }
                    if (\Omnichannel\Addons\SearchIntelligence\Support\KeywordGroupSchema::topicCandidateOverrideReady()) {
                        $payload['topic_candidate_override'] = null;
                    }
                    SeoKeywordGroupKeyword::query()->create($payload);
                }
            }

            return ['groups' => $writtenGroups, 'members' => $writtenMembers];
        });
    }

    /**
     * Optional Industry Group support. Absence is not a grouping failure.
     * Transport failures from the matcher propagate.
     *
     * @param  list<array{ref: string, text: string}>  $payload
     * @return array{status: string, evidence: list<array{ref: string, memberships: list<array{key: string, group_type: string}>}>, keyword_count: int, membership_count: int}
     */
    private function industryEvidence(int $siteId, ?string $language, array $payload): array
    {
        $none = [
            'status' => 'no_industry_context',
            'evidence' => [],
            'keyword_count' => 0,
            'membership_count' => 0,
        ];
        if ($payload === [] || $this->industryKeys === null || $this->industryMatcher === null) {
            return $none;
        }

        $key = $this->industryKeys->keyForSite($siteId);
        if ($key === null || trim($key) === '') {
            return $none;
        }

        $entities = [];
        foreach ($payload as $row) {
            $entities[] = new IndustryGroupMatchEntity((string) $row['ref'], (string) $row['text']);
        }

        $result = $this->industryMatcher->match(
            scopeRef: 'keyword-group:'.$siteId,
            entities: $entities,
            siteId: $siteId,
            industryContextKey: $key,
            locale: $this->normalizeLanguage($language),
        );

        if (! $result->calledPython) {
            return [
                'status' => $result->reason !== '' ? $result->reason : 'no_industry_groups',
                'evidence' => [],
                'keyword_count' => 0,
                'membership_count' => 0,
            ];
        }

        $evidence = [];
        $membershipCount = 0;
        foreach ($result->entities as $entity) {
            $memberships = [];
            $seen = [];
            foreach ($entity->evidence as $row) {
                if ($row->suggestedMatch !== true) {
                    continue;
                }
                $groupType = trim((string) ($row->groupType ?? ''));
                if ($groupType === '' || isset($seen[$row->industryGroupKey])) {
                    continue;
                }
                $seen[$row->industryGroupKey] = true;
                $memberships[] = [
                    'key' => $row->industryGroupKey,
                    'group_type' => $groupType,
                ];
            }
            if ($memberships === []) {
                continue;
            }
            $evidence[] = ['ref' => $entity->ref, 'memberships' => $memberships];
            $membershipCount += count($memberships);
        }

        return [
            'status' => $evidence === [] ? 'no_suggested_matches' : 'used',
            'evidence' => $evidence,
            'keyword_count' => count($evidence),
            'membership_count' => $membershipCount,
        ];
    }

    /**
     * Empty eligible input removes only unlocked semantic groups whose hash belongs
     * to an analysis run of this language. Unknown hashes and other languages stay.
     */
    private function clearGroupsOwnedByLanguage(int $siteId, ?string $language): int
    {
        return (int) DB::connection('omi_seo_ai')->transaction(function () use ($siteId, $language): int {
            $unknown = SeoKeywordGroup::query()
                ->where('site_id', $siteId)
                ->where('source', KeywordGroupSource::SEMANTIC)
                ->where('is_locked', false)
                ->where(function ($query): void {
                    $query->whereNull('input_hash')->orWhere('input_hash', '');
                })
                ->count();

            $hashes = $this->ownedDatasetHashes($siteId, $language);
            if ($hashes === []) {
                return $unknown;
            }

            $ownedIds = SeoKeywordGroup::query()
                ->where('site_id', $siteId)
                ->where('source', KeywordGroupSource::SEMANTIC)
                ->where('is_locked', false)
                ->whereIn('input_hash', $hashes)
                ->pluck('id');
            if ($ownedIds->isEmpty()) {
                return $unknown;
            }

            SeoKeywordGroupKeyword::query()
                ->where('site_id', $siteId)
                ->whereIn('group_id', $ownedIds->all())
                ->delete();
            SeoKeywordGroup::query()->whereIn('id', $ownedIds->all())->delete();

            return $unknown;
        });
    }

    /**
     * @return list<string>
     */
    private function ownedDatasetHashes(int $siteId, ?string $language): array
    {
        if ($siteId <= 0 || ! Schema::connection('omi_seo_ai')->hasTable('seo_keyword_group_analysis_runs')) {
            return [];
        }

        $query = SeoKeywordGroupAnalysisRun::query()
            ->where('site_id', $siteId)
            ->whereNotNull('dataset_hash')
            ->where('dataset_hash', '!=', '');
        if ($language === null || $language === '') {
            $query->whereNull('language');
        } else {
            $query->where('language', $language);
        }

        return $query->pluck('dataset_hash')
            ->map(static fn ($hash): string => (string) $hash)
            ->unique()
            ->values()
            ->all();
    }

    /**
     * Dataset hashes owned by a different language. Those semantic groups stay untouched.
     *
     * @return list<string>
     */
    private function foreignDatasetHashes(int $siteId, ?string $language): array
    {
        if ($siteId <= 0 || ! Schema::connection('omi_seo_ai')->hasTable('seo_keyword_group_analysis_runs')) {
            return [];
        }

        $query = SeoKeywordGroupAnalysisRun::query()
            ->where('site_id', $siteId)
            ->whereNotNull('dataset_hash')
            ->where('dataset_hash', '!=', '');
        if ($language === null || $language === '') {
            $query->whereNotNull('language');
        } else {
            $query->where(function ($languages) use ($language): void {
                $languages->whereNull('language')->orWhere('language', '!=', $language);
            });
        }

        return $query->pluck('dataset_hash')
            ->map(static fn ($hash): string => (string) $hash)
            ->unique()
            ->values()
            ->all();
    }

    private function storedMembershipLanguage(?string $language): ?string
    {
        if (! KeywordGroupSchema::membershipLanguageReady()) {
            return null;
        }

        return KeywordGroupSchema::membershipLanguageKey($language);
    }

    private function normalizeLanguage(?string $language): ?string
    {
        $language = trim((string) $language);

        return $language === '' ? null : mb_substr($language, 0, 32);
    }
}
