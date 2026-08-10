<?php

declare(strict_types=1);

return [
    'session_ttl_minutes' => 120,
    'poll_min_seconds' => 5,
    'max_plan_steps' => 8,
    'max_items_per_request' => 50,

    'planner' => [
        'max_steps' => 20,
        'max_write_steps' => 15,
        'max_publish_steps' => 1,
        'max_archive_steps' => 1,
        'max_replan' => 3,
        'daily_action_budget' => 500,
    ],

    'executor' => [
        'poll_min_seconds' => 5,
        'max_step_retries' => 4,
        'backoff' => [60, 300, 900, 3600],
    ],

    'retention' => [
        'plan_days' => 60,
        'approval_days' => 30,
    ],

    'automation' => [
        'enabled_triggers' => ['manual', 'api', 'scheduled'],
    ],

    'rate_limits' => [
        'request' => [
            'max_attempts' => 120,
            'decay_seconds' => 60,
        ],
        'poll' => [
            'max_attempts' => 30,
            'decay_seconds' => 60,
        ],
        'create' => [
            'max_attempts' => 10,
            'decay_seconds' => 3600,
        ],
        'archive' => [
            'max_attempts' => 5,
            'decay_seconds' => 3600,
        ],
    ],
];
