<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Semantic\ConceptMatching;

use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticDisabledException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticInvalidResponseException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\SemanticAnalyticsClient;

/**
 * Typed wrapper for POST /v1/concept-matches/analyses.
 * Transport authority remains SemanticAnalyticsClient (health/alert path).
 */
final class ConceptMatchingClient
{
    public function __construct(
        private readonly SemanticAnalyticsClient $client,
    ) {}

    public function analyze(ConceptMatchRequest $request): ConceptMatchResult
    {
        if (! (bool) config('semantic.enabled', false)) {
            throw SemanticDisabledException::disabled();
        }

        $this->assertUniqueEntityRefs($request->entities);
        if ($request->entities === [] || $request->concepts === []) {
            throw SemanticInvalidResponseException::contract(
                'concept match request requires at least one entity and one concept',
            );
        }

        $payload = $request->toApiArray();
        $response = $this->client->postJson('/v1/concept-matches/analyses', $payload);

        return $this->parseResponse($response, $request);
    }

    /**
     * Analyze entities in configured batches; concepts reused each batch.
     * Preserves caller entity order in the merged result.
     *
     * @param  list<ConceptMatchEntity>  $entities
     * @param  list<ConceptDefinition>  $concepts
     */
    public function analyzeBatched(
        string $scopeRef,
        array $entities,
        array $concepts,
        ?string $language = null,
        ?ConceptDecisionPolicy $decisionPolicy = null,
        ?int $batchSize = null,
    ): ConceptMatchResult {
        if (! (bool) config('semantic.enabled', false)) {
            throw SemanticDisabledException::disabled();
        }

        $this->assertUniqueEntityRefs($entities);
        if ($entities === [] || $concepts === []) {
            throw SemanticInvalidResponseException::contract(
                'concept match request requires at least one entity and one concept',
            );
        }

        $size = $batchSize ?? (int) config('semantic.concept_match_entity_batch_size', 100);
        $size = max(1, $size);
        $chunks = array_chunk($entities, $size);

        $mergedEntities = [];
        $last = null;
        foreach ($chunks as $index => $chunk) {
            $batchScope = count($chunks) === 1
                ? $scopeRef
                : $scopeRef.'#batch-'.($index + 1);
            $last = $this->analyze(new ConceptMatchRequest(
                scopeRef: $batchScope,
                entities: $chunk,
                concepts: $concepts,
                language: $language,
                decisionPolicy: $decisionPolicy,
            ));
            foreach ($last->entities as $entityResult) {
                $mergedEntities[] = $entityResult;
            }
        }

        assert($last instanceof ConceptMatchResult);

        return new ConceptMatchResult(
            analysisId: $last->analysisId,
            scopeRef: $scopeRef,
            language: $language,
            entities: $mergedEntities,
            diagnostics: $last->diagnostics,
        );
    }

    /**
     * @param  list<ConceptMatchEntity>  $entities
     */
    private function assertUniqueEntityRefs(array $entities): void
    {
        $seen = [];
        foreach ($entities as $entity) {
            if ($entity->ref === '') {
                throw new \InvalidArgumentException('Concept match entity ref must not be blank');
            }
            if (isset($seen[$entity->ref])) {
                throw new \InvalidArgumentException(
                    'Duplicate concept match entity ref: '.$entity->ref,
                );
            }
            $seen[$entity->ref] = true;
        }
    }

    /**
     * @param  array<string, mixed>  $response
     */
    private function parseResponse(array $response, ConceptMatchRequest $request): ConceptMatchResult
    {
        $analysisId = trim((string) ($response['analysis_id'] ?? ''));
        if ($analysisId === '') {
            throw SemanticInvalidResponseException::contract('missing analysis_id');
        }

        $scopeRef = (string) ($response['scope_ref'] ?? '');
        if ($scopeRef === '') {
            throw SemanticInvalidResponseException::contract('missing scope_ref');
        }

        $entitiesRaw = $response['entities'] ?? null;
        if (! is_array($entitiesRaw)) {
            throw SemanticInvalidResponseException::contract('entities must be an array');
        }

        $expectedRefs = array_map(
            static fn (ConceptMatchEntity $e): string => $e->ref,
            $request->entities,
        );
        $expectedKeys = array_map(
            static fn (ConceptDefinition $c): string => $c->key,
            $request->concepts,
        );
        $expectedKeySet = array_fill_keys($expectedKeys, true);

        $seenRefs = [];
        $entityResults = [];
        foreach ($entitiesRaw as $row) {
            if (! is_array($row)) {
                throw SemanticInvalidResponseException::contract('entity row must be an object');
            }
            $ref = (string) ($row['ref'] ?? '');
            if ($ref === '') {
                throw SemanticInvalidResponseException::contract('entity ref missing');
            }
            if (isset($seenRefs[$ref])) {
                throw SemanticInvalidResponseException::contract('duplicate entity ref in response: '.$ref);
            }
            $seenRefs[$ref] = true;

            $conceptsRaw = $row['concepts'] ?? null;
            if (! is_array($conceptsRaw)) {
                throw SemanticInvalidResponseException::contract('entity concepts must be an array');
            }

            $conceptEvidence = [];
            $seenKeys = [];
            foreach ($conceptsRaw as $conceptRow) {
                if (! is_array($conceptRow)) {
                    throw SemanticInvalidResponseException::contract('concept row must be an object');
                }
                $key = (string) ($conceptRow['key'] ?? '');
                if ($key === '' || ! isset($expectedKeySet[$key])) {
                    throw SemanticInvalidResponseException::contract('unexpected concept key: '.$key);
                }
                if (isset($seenKeys[$key])) {
                    throw SemanticInvalidResponseException::contract('duplicate concept key in entity: '.$key);
                }
                $seenKeys[$key] = true;
                $conceptEvidence[] = $this->parseConceptEvidence($conceptRow);
            }

            foreach ($expectedKeys as $expectedKey) {
                if (! isset($seenKeys[$expectedKey])) {
                    throw SemanticInvalidResponseException::contract(
                        'missing concept key in response for ref '.$ref.': '.$expectedKey,
                    );
                }
            }

            $entityResults[] = new ConceptMatchEntityResult(
                ref: $ref,
                text: (string) ($row['text'] ?? ''),
                concepts: $conceptEvidence,
            );
        }

        foreach ($expectedRefs as $expectedRef) {
            if (! isset($seenRefs[$expectedRef])) {
                throw SemanticInvalidResponseException::contract(
                    'missing entity ref in response: '.$expectedRef,
                );
            }
        }

        $byRef = [];
        foreach ($entityResults as $entityResult) {
            $byRef[$entityResult->ref] = $entityResult;
        }
        $ordered = [];
        foreach ($expectedRefs as $expectedRef) {
            $ordered[] = $byRef[$expectedRef];
        }

        return new ConceptMatchResult(
            analysisId: $analysisId,
            scopeRef: $scopeRef,
            language: isset($response['language']) ? (string) $response['language'] : null,
            entities: $ordered,
            diagnostics: $this->parseDiagnostics(
                is_array($response['diagnostics'] ?? null) ? $response['diagnostics'] : [],
            ),
        );
    }

    /**
     * @param  array<string, mixed>  $row
     */
    private function parseConceptEvidence(array $row): ConceptMatchEvidence
    {
        $lexicalRaw = $row['lexical'] ?? null;
        if (! is_array($lexicalRaw)) {
            throw SemanticInvalidResponseException::contract('lexical evidence missing');
        }
        if (! array_key_exists('matched', $lexicalRaw)) {
            throw SemanticInvalidResponseException::contract('lexical.matched missing');
        }

        $strategy = (string) ($row['matching_strategy'] ?? '');
        if ($strategy === '') {
            throw SemanticInvalidResponseException::contract('matching_strategy missing');
        }

        $suggested = $row['suggested_match'] ?? null;
        if ($suggested !== null && ! is_bool($suggested)) {
            throw SemanticInvalidResponseException::contract('suggested_match must be bool|null');
        }

        return new ConceptMatchEvidence(
            key: (string) $row['key'],
            lexical: new LexicalEvidence(
                matched: (bool) $lexicalRaw['matched'],
                matchMode: (string) ($lexicalRaw['match_mode'] ?? ''),
                matchedExamples: $this->stringList($lexicalRaw['matched_examples'] ?? []),
                bestMatch: isset($lexicalRaw['best_match']) && $lexicalRaw['best_match'] !== null
                    ? (string) $lexicalRaw['best_match']
                    : null,
                negativeMatched: (bool) ($lexicalRaw['negative_matched'] ?? false),
                negativeMatchedExamples: $this->stringList($lexicalRaw['negative_matched_examples'] ?? []),
            ),
            matchingStrategy: $strategy,
            positiveMax: $this->nullableFloat($row['positive_max'] ?? null, 'positive_max'),
            positiveTopKMean: $this->nullableFloat($row['positive_top_k_mean'] ?? null, 'positive_top_k_mean'),
            negativeMax: $this->nullableFloat($row['negative_max'] ?? null, 'negative_max'),
            margin: $this->nullableFloat($row['margin'] ?? null, 'margin'),
            bestPositiveExample: isset($row['best_positive_example']) && $row['best_positive_example'] !== null
                ? (string) $row['best_positive_example']
                : null,
            bestPositiveSimilarity: $this->nullableFloat(
                $row['best_positive_similarity'] ?? null,
                'best_positive_similarity',
            ),
            bestNegativeExample: isset($row['best_negative_example']) && $row['best_negative_example'] !== null
                ? (string) $row['best_negative_example']
                : null,
            bestNegativeSimilarity: $this->nullableFloat(
                $row['best_negative_similarity'] ?? null,
                'best_negative_similarity',
            ),
            suggestedMatch: $suggested,
        );
    }

    /**
     * @param  array<string, mixed>  $raw
     */
    private function parseDiagnostics(array $raw): ConceptMatchDiagnostics
    {
        return new ConceptMatchDiagnostics(
            entityCount: (int) ($raw['entity_count'] ?? 0),
            conceptCount: (int) ($raw['concept_count'] ?? 0),
            uniqueTextCount: (int) ($raw['unique_text_count'] ?? 0),
            embedMs: (int) ($raw['embed_ms'] ?? 0),
            scoreMs: (int) ($raw['score_ms'] ?? 0),
            totalMs: (int) ($raw['total_ms'] ?? 0),
            model: (string) ($raw['model'] ?? ''),
            provider: (string) ($raw['provider'] ?? ''),
            dimensions: (int) ($raw['dimensions'] ?? 0),
            cache: (string) ($raw['cache'] ?? ''),
            semanticConcepts: (int) ($raw['semantic_concepts'] ?? 0),
            lexicalOnlyConcepts: (int) ($raw['lexical_only_concepts'] ?? 0),
        );
    }

    private function nullableFloat(mixed $value, string $field): ?float
    {
        if ($value === null) {
            return null;
        }
        if (! is_int($value) && ! is_float($value) && ! (is_string($value) && is_numeric($value))) {
            throw SemanticInvalidResponseException::contract($field.' must be numeric|null');
        }

        return (float) $value;
    }

    /**
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }
        $out = [];
        foreach ($value as $item) {
            $out[] = (string) $item;
        }

        return $out;
    }
}
