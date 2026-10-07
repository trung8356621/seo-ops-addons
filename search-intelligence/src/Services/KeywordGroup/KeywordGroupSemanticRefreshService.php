<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup;

use Illuminate\Support\Facades\DB;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordGroup\KeywordGroupSource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupKeyword;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticAnalyticsClient;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticInvalidResponseException;
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
        $payload = [];
        $known = [];
        foreach ($keywords as $row) {
            $keywordId = (int) ($row['keyword_id'] ?? 0);
            $text = $this->hasher->normalizeText((string) ($row['phrase'] ?? ''));
            if ($keywordId <= 0 || $text === '' || isset($protectedKeywords[$keywordId]) || isset($known[$keywordId])) {
                continue;
            }
            $ref = (string) $keywordId;
            $known[$keywordId] = true;
            $payload[] = ['ref' => $ref, 'text' => $text];
        }

        if ($payload === []) {
            return new KeywordGroupRefreshResult(0, 0, count($protectedGroupIds), true);
        }

        $scopeRef = (string) $siteId;
        $language = $this->normalizeLanguage($language);
        $response = $this->client->postJson('/v1/keyword-groups/analyses', [
            'scope_ref' => $scopeRef,
            'language' => $language,
            'keywords' => $payload,
        ], 'keyword-group-'.$scopeRef);

        $parsed = $this->parseResponse($response, $known);
        $this->replaceSemanticGroups($siteId, $parsed['groups'], $parsed['input_hash'], $parsed['algorithm'], $protectedKeywords);

        return new KeywordGroupRefreshResult(
            count($parsed['groups']),
            array_sum(array_map(static fn (array $group): int => count($group['members']), $parsed['groups'])),
            count($protectedGroupIds),
            false,
        );
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
     */
    private function replaceSemanticGroups(
        int $siteId,
        array $groups,
        ?string $inputHash,
        ?string $algorithm,
        array $protectedKeywords,
    ): void {
        DB::connection('omi_seo_ai')->transaction(function () use ($siteId, $groups, $inputHash, $algorithm, $protectedKeywords): void {
            $protectedGroupIds = SeoKeywordGroup::query()
                ->where('site_id', $siteId)
                ->get()
                ->filter(static fn (SeoKeywordGroup $group): bool => $group->isProtectedFromSemanticRefresh())
                ->map(static fn (SeoKeywordGroup $group): int => (int) $group->id)
                ->all();

            $memberDelete = SeoKeywordGroupKeyword::query()->where('site_id', $siteId);
            $groupDelete = SeoKeywordGroup::query()->where('site_id', $siteId);
            if ($protectedGroupIds !== []) {
                $memberDelete->whereNotIn('group_id', $protectedGroupIds);
                $groupDelete->whereNotIn('id', $protectedGroupIds);
            }
            $memberDelete->delete();
            $groupDelete->delete();

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
                    if (\Omnichannel\Addons\SearchIntelligence\Support\KeywordGroupSchema::topicCandidateReady()) {
                        $payload['is_topic_candidate'] = true;
                    }
                    if (\Omnichannel\Addons\SearchIntelligence\Support\KeywordGroupSchema::topicCandidateOverrideReady()) {
                        $payload['topic_candidate_override'] = null;
                    }
                    SeoKeywordGroupKeyword::query()->create($payload);
                }
            }
        });
    }

    private function normalizeLanguage(?string $language): ?string
    {
        $language = trim((string) $language);

        return $language === '' ? null : mb_substr($language, 0, 32);
    }
}
