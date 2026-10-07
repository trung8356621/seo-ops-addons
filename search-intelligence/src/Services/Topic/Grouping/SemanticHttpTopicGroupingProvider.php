<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping;

use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticHttpException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticInvalidResponseException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticAnalyticsClient;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\Grouping\Contracts\TopicGroupingProvider;

/**
 * TASK 1 provider backed by seo-ops-semantic HTTP analysis.
 *
 * Site recluster → POST /v1/topic/analyses (proposal only).
 * Membership scan stays on the legacy lexical path (no semantic scan API).
 *
 * Never mutates Topics, memberships, locks, or DNA.
 */
final class SemanticHttpTopicGroupingProvider implements TopicGroupingProvider
{
    public const KEY = 'semantic_http';

    public function __construct(
        private readonly SemanticAnalyticsClient $client,
        private readonly TopicGroupingInputHasher $hasher,
        private readonly LegacyTopicGroupingProvider $legacy,
    ) {}

    public function analyze(TopicGroupingInput $input): TopicGroupingProposal
    {
        return match ($input->scope) {
            TopicGroupingScope::SITE_RECLUSTER => $this->analyzeSiteRecluster($input),
            TopicGroupingScope::TOPIC_MEMBERSHIP_SCAN => $this->legacy->analyze($input),
            default => new TopicGroupingProposal(
                [],
                $input->candidates,
                [
                    TopicGroupingProposal::META_PROVIDER => self::KEY,
                    TopicGroupingProposal::META_SCOPE => $input->scope,
                    'unsupported_scope' => true,
                ],
            ),
        };
    }

    private function analyzeSiteRecluster(TopicGroupingInput $input): TopicGroupingProposal
    {
        $keywords = [];
        $byRef = [];
        foreach ($input->candidates as $candidate) {
            $ref = (string) $candidate->keywordRef;
            $text = $this->hasher->normalizeText($candidate->text);
            if ($ref === '' || $text === '') {
                continue;
            }
            if (isset($byRef[$ref])) {
                throw SemanticInvalidResponseException::contract('duplicate input keyword ref: '.$ref);
            }
            $byRef[$ref] = $candidate;
            $keywords[] = ['ref' => $ref, 'text' => $text];
        }

        if ($keywords === []) {
            throw SemanticInvalidResponseException::contract('no keywords to analyze');
        }

        $siteRef = (string) $input->siteRef;
        $inputHash = $this->hasher->hash($siteRef, $input->language, $keywords);
        $requestId = 'topic-group-'.$siteRef.'-'.substr($inputHash, 0, 12);

        try {
            $response = $this->client->postJson('/v1/topic/analyses', [
                'site_ref' => $siteRef,
                'language' => $input->language,
                'keywords' => $keywords,
                'input_hash' => $inputHash,
                'request_id' => $requestId,
            ], $requestId);
        } catch (SemanticHttpException $e) {
            throw $e;
        }

        return $this->mapResponse($input, $response, $inputHash, $byRef);
    }

    /**
     * @param  array<string, mixed>  $response
     * @param  array<string, TopicGroupingCandidate>  $byRef
     */
    private function mapResponse(
        TopicGroupingInput $input,
        array $response,
        string $expectedHash,
        array $byRef,
    ): TopicGroupingProposal {
        $status = (string) ($response['status'] ?? '');
        if ($status === 'failed') {
            $error = (string) ($response['error'] ?? 'semantic_analysis_failed');
            throw SemanticInvalidResponseException::contract($error);
        }
        if ($status !== 'completed') {
            throw SemanticInvalidResponseException::contract('unexpected status: '.$status);
        }

        $responseHash = (string) ($response['input_hash'] ?? '');
        if ($responseHash === '' || ! hash_equals($expectedHash, $responseHash)) {
            throw SemanticInvalidResponseException::contract('input_hash mismatch');
        }

        $analysisId = (string) ($response['analysis_id'] ?? '');
        if ($analysisId === '') {
            throw SemanticInvalidResponseException::contract('missing analysis_id');
        }

        $seenRefs = [];
        $groups = [];
        $rawGroups = $response['groups'] ?? null;
        if (! is_array($rawGroups)) {
            throw SemanticInvalidResponseException::contract('groups must be an array');
        }

        foreach ($rawGroups as $index => $rawGroup) {
            if (! is_array($rawGroup)) {
                throw SemanticInvalidResponseException::contract('group['.$index.'] must be an object');
            }
            $groups[] = $this->mapGroup($rawGroup, $byRef, $seenRefs, $index);
        }

        $unassigned = [];
        $rawUnassigned = $response['unassigned'] ?? [];
        if (! is_array($rawUnassigned)) {
            throw SemanticInvalidResponseException::contract('unassigned must be an array');
        }
        foreach ($rawUnassigned as $index => $row) {
            if (! is_array($row)) {
                throw SemanticInvalidResponseException::contract('unassigned['.$index.'] must be an object');
            }
            $ref = (string) ($row['keyword_ref'] ?? '');
            $candidate = $this->requireKnownRef($ref, $byRef, $seenRefs, 'unassigned');
            $unassigned[] = $candidate;
        }

        $model = is_array($response['model'] ?? null) ? $response['model'] : [];
        $diagnostics = is_array($response['diagnostics'] ?? null) ? $response['diagnostics'] : [];

        return new TopicGroupingProposal(
            $groups,
            $unassigned,
            [
                TopicGroupingProposal::META_PROVIDER => self::KEY,
                TopicGroupingProposal::META_SCOPE => $input->scope,
                'input_hash' => $responseHash,
                'external_analysis_id' => $analysisId,
                'request_id' => $response['request_id'] ?? null,
                'model' => $model,
                'diagnostics' => $diagnostics,
                'duration_ms' => (int) ($response['duration_ms'] ?? 0),
                'started_at' => $response['started_at'] ?? null,
                'finished_at' => $response['finished_at'] ?? null,
                'algorithm' => (string) ($diagnostics['algorithm'] ?? ''),
                'low_confidence_member_count' => (int) ($diagnostics['low_confidence_member_count'] ?? 0),
            ],
            $analysisId,
        );
    }

    /**
     * @param  array<string, mixed>  $rawGroup
     * @param  array<string, TopicGroupingCandidate>  $byRef
     * @param  array<string, true>  $seenRefs
     */
    private function mapGroup(array $rawGroup, array $byRef, array &$seenRefs, int $index): TopicGroupingGroup
    {
        $groupKey = (string) ($rawGroup['group_ref'] ?? '');
        $label = (string) ($rawGroup['suggested_label'] ?? '');
        $membersRaw = $rawGroup['members'] ?? null;
        if ($groupKey === '' || $label === '' || ! is_array($membersRaw) || $membersRaw === []) {
            throw SemanticInvalidResponseException::contract('malformed group at index '.$index);
        }

        $members = [];
        foreach ($membersRaw as $memberIndex => $memberRaw) {
            if (! is_array($memberRaw)) {
                throw SemanticInvalidResponseException::contract(
                    'group['.$index.'].members['.$memberIndex.'] must be an object',
                );
            }
            $ref = (string) ($memberRaw['keyword_ref'] ?? '');
            $candidate = $this->requireKnownRef($ref, $byRef, $seenRefs, 'group');
            $similarity = isset($memberRaw['similarity_score']) ? (float) $memberRaw['similarity_score'] : null;
            $confidence = isset($memberRaw['confidence']) ? (float) $memberRaw['confidence'] : null;
            $members[] = new TopicGroupingMember(
                $candidate->keywordRef,
                $candidate->text,
                $confidence,
                [
                    'similarity_score' => $similarity,
                    'is_representative' => (bool) ($memberRaw['is_representative'] ?? false),
                    'semantic_text' => (string) ($memberRaw['text'] ?? $candidate->text),
                ],
            );
        }

        return new TopicGroupingGroup(
            $groupKey,
            $label,
            $members,
            null,
            [
                'member_count' => (int) ($rawGroup['member_count'] ?? count($members)),
                'mean_similarity' => isset($rawGroup['mean_similarity']) ? (float) $rawGroup['mean_similarity'] : null,
                'min_similarity' => isset($rawGroup['min_similarity']) ? (float) $rawGroup['min_similarity'] : null,
                'cohesion' => isset($rawGroup['cohesion']) ? (float) $rawGroup['cohesion'] : null,
            ],
        );
    }

    /**
     * @param  array<string, TopicGroupingCandidate>  $byRef
     * @param  array<string, true>  $seenRefs
     */
    private function requireKnownRef(
        string $ref,
        array $byRef,
        array &$seenRefs,
        string $context,
    ): TopicGroupingCandidate {
        if ($ref === '' || ! isset($byRef[$ref])) {
            throw SemanticInvalidResponseException::contract('unknown '.$context.' keyword_ref: '.$ref);
        }
        if (isset($seenRefs[$ref])) {
            throw SemanticInvalidResponseException::contract('duplicate returned keyword_ref: '.$ref);
        }
        $seenRefs[$ref] = true;

        return $byRef[$ref];
    }
}
