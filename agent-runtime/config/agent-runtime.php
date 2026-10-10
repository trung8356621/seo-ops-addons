<?php

declare(strict_types=1);

return [
    /*
    | Need scores are probabilities from the decision model (0..1).
    | A resource is fetched only when its need score is greater than or equal
    | to this value. Conservative default: 0.5. This is not a business policy
    | beyond "include a resource the decision model marked as likely needed".
    */
    'need_threshold' => (float) env('AGENT_RUNTIME_NEED_THRESHOLD', 0.5),

    'answer_max_output_tokens' => (int) env('AGENT_RUNTIME_ANSWER_MAX_OUTPUT', 2048),

    'decision_max_output_tokens' => (int) env('AGENT_RUNTIME_DECISION_MAX_OUTPUT', 800),

    /*
    | Server-only permanent Service API bearer (svc_live_…).
    | Raw keys are not stored in service_api_credentials (hash only), so the
    | runtime cannot mint SEO Access unless this is configured.
    | Never expose this value to the model or the Copy export.
    */
    'seo_access_bearer' => env('AGENT_RUNTIME_SEO_ACCESS_BEARER'),

    /*
    | Origin used for POST /api/v1/services/seo/access and as the only host
    | the executor may call. Empty means the current application URL.
    */
    'seo_access_base_url' => env('AGENT_RUNTIME_SEO_ACCESS_BASE_URL'),

    /*
    | Internal standalone host / development harness navigation in Filament panels.
    | Default is false to hide the harness from user-facing sidebar navigation.
    */
    'standalone_harness_navigation' => (bool) env('AGENT_RUNTIME_STANDALONE_HARNESS_NAVIGATION', false),

    /*
    | Internal standalone host direct route access (/seo/agent-runtime).
    | Default is false so normal end-users cannot directly access the harness.
    | When true (or for owner/admin accounts), direct access is permitted.
    */
    'standalone_harness_enabled' => (bool) env('AGENT_RUNTIME_STANDALONE_HARNESS_ENABLED', false),

    /*
    | Local semantic tool router.
    | A coordinator constructed with LocalAgentToolRouter never calls the
    | decision model. This flag still gates topic-group intersection and the
    | container fallback used when the router was not injected.
    | Model groups, including JEV, stay available for user-selected routing.
    */
    'local_tool_router' => [
        'enabled' => (bool) env('AGENT_RUNTIME_LOCAL_TOOL_ROUTER', false),
    ],

    'feedback' => [
        'url' => env('SEMANTIC_URL', 'http://127.0.0.1:8088'),
        'internal_token' => env('SEMANTIC_INTERNAL_API_TOKEN', ''),
        'installation_id' => env('AGENT_RUNTIME_INSTALLATION_ID', hash('sha256', (string) env('APP_URL', 'local'))),
        'timeout' => (int) env('AGENT_RUNTIME_FEEDBACK_TIMEOUT', 10),
    ],
];
