<?php

declare(strict_types=1);

return [
    'dashboard' => [
        'nav' => 'Dashboard',
        'title' => 'Social Automation',
        'intro' => 'Central control plane for RPA Social Automation. Global scope — no selected website required.',
        'empty_schema' => 'Registry tables are not migrated yet, or no Runners have reported in. Counts stay at zero until migrate + Runner enroll.',
    ],
    'service' => [
        'nav' => 'Service status',
        'title' => 'Service status',
        'db_note' => 'Dedicated omi_social_automation plane. OPS-SERVER provisions entitlement and optional DB credentials.',
    ],
    'packages' => [
        'nav' => 'Packages',
        'title' => 'Package Registry',
        'intro' => 'Immutable Widget/Workflow releases published by RPA Admin via Service API.',
        'empty' => 'No packages registered yet.',
    ],
    'agents' => [
        'nav' => 'Agents',
        'title' => 'Agents',
        'intro' => 'Runner machines. Online/offline is computed from heartbeat age on the server.',
        'empty' => 'No agents registered. Enroll a Runner credential then call register/heartbeat.',
    ],
    'accounts' => [
        'nav' => 'Accounts',
        'title' => 'Accounts',
        'intro' => 'Platform account identities. Declared email/username is not verified identity.',
        'empty' => 'No account metadata yet. Synced from Runner session inventory.',
    ],
    'sessions' => [
        'nav' => 'Browser Sessions',
        'title' => 'Browser Sessions',
        'intro' => 'Ready means operator-confirmed readiness — not verified social login.',
        'empty' => 'No browser sessions synced.',
    ],
    'tasks' => [
        'nav' => 'Tasks',
        'title' => 'Tasks',
        'intro' => 'Work units referencing a pinned Widget/Workflow package version.',
        'empty' => 'No tasks yet. Create via Admin Service API (social-automation:admin:task).',
    ],
    'schedules' => [
        'nav' => 'Schedules',
        'title' => 'Schedules',
        'intro' => 'Cron triggers. Offline agents are skipped — no catch-up backlog.',
        'empty' => 'No schedules configured.',
    ],
    'jobs' => [
        'nav' => 'Jobs & Runs',
        'title' => 'Jobs & Runs',
        'intro' => 'Execution jobs with lease/unknown reconciliation. dispatched ≠ completed.',
        'empty' => 'No jobs yet.',
    ],
];
