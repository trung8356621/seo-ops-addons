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
];
