<?php

declare(strict_types=1);

return [
    'lock_ttl_seconds' => max(30, (int) env('ARTICLE_EDITOR_LOCK_TTL_SECONDS', 120)),
    'heartbeat_seconds' => max(10, (int) env('ARTICLE_EDITOR_HEARTBEAT_SECONDS', 30)),
    'server_autosave_debounce_ms' => max(1000, (int) env('ARTICLE_EDITOR_SERVER_AUTOSAVE_DEBOUNCE_MS', 4000)),

    /**
     * Phase 5A — TipTap JSON persistence rollout.
     * Rollback: set read_preferred=false (body HTML ingest fallback). Do not drop JSON columns.
     */
    'json_persistence' => [
        'enabled' => (bool) env('ARTICLE_EDITOR_JSON_PERSISTENCE_ENABLED', true),
        'dual_write' => (bool) env('ARTICLE_EDITOR_JSON_WRITE_DUAL', true),
        'read_preferred' => (bool) env('ARTICLE_EDITOR_JSON_READ_PREFERRED', true),
        'publish_from_json' => (bool) env('ARTICLE_EDITOR_JSON_PUBLISH_FROM_JSON', false),
        'max_payload_bytes' => (int) env('ARTICLE_EDITOR_JSON_MAX_PAYLOAD_BYTES', 2_000_000),
        'max_node_count' => (int) env('ARTICLE_EDITOR_JSON_MAX_NODE_COUNT', 50_000),
        'max_depth' => (int) env('ARTICLE_EDITOR_JSON_MAX_DEPTH', 40),
    ],
];
