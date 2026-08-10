<?php

declare(strict_types=1);

return [
    'clustering_strategy_default' => 'balanced',
    'max_topic_depth' => 4,

    // Consumed directly by KeywordIntelligenceQuotaGuard / TopicalMapBuilder /
    // KeywordWorkspaceAnalysisService / KeywordCannibalizationService.
    'limits' => [
        'max_workspaces_per_site' => 50,
        'max_keywords_per_import' => 2000,
        'max_keywords_per_workspace' => 20000,
        'max_clusters_per_convert' => 200,
        'convert_confirmation_threshold' => 10,
    ],
    'clustering' => [
        'default_strategy' => 'balanced',
        'max_cluster_size' => 40,
    ],
    'topical_map' => [
        'max_depth' => 4,
        'default_mode' => 'balanced',
        'lock_ttl_seconds' => 900,
        'max_topics_per_workspace' => 500,
        'max_clusters_per_map_build' => 500,
        'max_link_suggestions' => 2000,
        'max_versions_per_workspace' => 50,
        'map_build_operations_per_hour' => 20,
        'modes' => [
            'conservative' => ['max_depth' => 3],
            'balanced' => ['max_depth' => 4],
            'expansive' => ['max_depth' => 5],
        ],
    ],
    'conversion' => [
        'max_items_per_project' => 200,
        'max_items_per_conversion' => 200,
        'max_projects_per_conversion' => 1,
        'conversion_operations_per_hour' => 10,
        'default_policy' => 'new_only',
        'default_grouping' => 'single_project',
    ],
    'cannibalization' => [
        'multi_mapping_threshold' => 2,
    ],

    'scoring' => [
        'version' => '1',
        'weights' => [
            'relevance' => 0.30,
            'business_value' => 0.25,
            'opportunity' => 0.25,
            'intent' => 0.10,
        ],
        'penalties' => [
            'cannibalization' => 15,
            'existing_coverage' => 10,
        ],
    ],
    'quotas' => [
        'workspaces_per_tenant' => 50,
        'keywords_per_workspace' => 10000,
        'keywords_per_import' => 2000,
        'analysis_operations_per_hour' => 20,
        'clusters_converted_per_project' => 200,
        'max_candidate_pairs' => 5000,
        'max_bulk_review_items' => 500,
        'max_clusters_per_workspace' => 500,
        'max_ai_keywords_per_operation' => 200,
    ],

    // Keyword Intelligence Phase 2 — core services (normalization/intent/near-duplicate/lock).
    'analysis' => [
        'lock_ttl_seconds' => 900,
        'stages' => [
            'normalizing',
            'deduplicate',
            'classifying_intent',
            'scoring',
            'mapping_content',
            'clustering',
            'detecting_cannibalization',
            'finalize',
            'completed',
            'failed',
        ],
        'max_keywords_per_analysis' => 5000,
        'max_ai_keywords_per_operation' => 200,
    ],

    'normalization' => [
        'max_keyword_length' => 500,
    ],

    'near_duplicate' => [
        'threshold' => 88,
        'max_bucket_size' => 200,
        'max_candidate_pairs_per_keyword' => 20,
    ],

    'intent' => [
        'ai_confidence_threshold' => 0.55,
        'classifier_version' => 'rule-v1',
    ],
];
