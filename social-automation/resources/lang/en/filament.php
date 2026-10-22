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
    'scripts' => [
        'nav' => 'Scripts',
        'title' => 'Scripts',
        'intro' => 'Script Registry — immutable releases. Catalog syncs bidirectionally with RPA Admin; executable editing stays in Electron.',
        'empty' => 'No Scripts yet. Sync from RPA Admin after login.',
    ],
    'widgets' => [
        'nav' => 'Widgets',
        'title' => 'Widgets',
        'intro' => 'Form Builder over a pinned Script. Auto-disabled when execution digest changes (script_updated); review bindings before re-enable.',
        'empty' => 'No Widgets yet. Publish from the form below or Service API.',
    ],
    'workflows' => [
        'nav' => 'Workflows',
        'title' => 'Workflows',
        'intro' => 'Sequential Widget chains with output bindings. Dispatch is blocked when a dependent Widget is disabled.',
        'empty' => 'No Workflows yet.',
    ],
    'agents' => [
        'nav' => 'Clients',
        'title' => 'Clients',
        'intro' => 'Runner machines (Clients). Online/offline is computed from heartbeat. RPA Admin does not count as Runner online.',
        'empty' => 'No Clients registered. Enroll a Runner credential then call register/heartbeat.',
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
