<?php

declare(strict_types=1);

/**
 * Semantic analytics HTTP integration (seo-ops-semantic).
 *
 * Default is safe: disabled + legacy Topic grouping provider.
 * Enabling Docker alone does not switch production behavior.
 */
return [
    'enabled' => (bool) env('SEMANTIC_ENABLED', false),

    'url' => rtrim((string) env('SEMANTIC_URL', 'http://127.0.0.1:8088'), '/'),

    /** Total request timeout (seconds). Cold model load + site analysis can exceed 30s. */
    'timeout' => (int) env('SEMANTIC_TIMEOUT', 120),

    /** TCP/TLS connect timeout (seconds). Fail fast when semantic host is unreachable. */
    'connect_timeout' => (int) env('SEMANTIC_CONNECT_TIMEOUT', 5),

    /**
     * Topic grouping provider selector.
     * legacy | semantic_http
     */
    'topic_provider' => (string) env(
        'TOPIC_GROUPING_PROVIDER',
        (string) env('SEMANTIC_TOPIC_PROVIDER', 'legacy'),
    ),

    /**
     * Entity batch size for POST /v1/concept-matches/analyses.
     * Concepts are reused across batches; entities are chunked.
     */
    'concept_match_entity_batch_size' => max(
        1,
        (int) env('SEMANTIC_CONCEPT_MATCH_ENTITY_BATCH_SIZE', 100),
    ),

    /**
     * Keyword embedding batch size for large semantic grouping.
     * Must stay at or below Python TOPIC_MAX_KEYWORDS (2000).
     * Sets larger than this are clamped. One global cluster follows the batches.
     */
    'keyword_group_batch_size' => max(1, min(2000, (int) env('SEMANTIC_KEYWORD_GROUP_BATCH_SIZE', 1000))),

    /**
     * Full-set cluster call. Pairwise hybrid grouping for several thousand
     * keywords runs far longer than a single embedding batch.
     */
    'keyword_group_cluster_timeout' => max(120, (int) env('SEMANTIC_KEYWORD_GROUP_CLUSTER_TIMEOUT', 1800)),

    /** Dataset batch upload. Independent of the long cluster timeout. */
    'keyword_group_dataset_batch_size' => max(1, min(1000, (int) env('SEMANTIC_KEYWORD_GROUP_DATASET_BATCH_SIZE', 1000))),

    'keyword_group_dataset_timeout' => max(1, (int) env('SEMANTIC_KEYWORD_GROUP_DATASET_TIMEOUT', 60)),

    'keyword_group_dataset_retries' => max(1, (int) env('SEMANTIC_KEYWORD_GROUP_DATASET_RETRIES', 3)),

    'keyword_group_dataset_lock_seconds' => max(30, (int) env('SEMANTIC_KEYWORD_GROUP_DATASET_LOCK_SECONDS', 600)),

    'wiki_suggestions' => (bool) env('SEMANTIC_WIKI_SUGGESTIONS', false),
];
