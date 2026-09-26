<?php

declare(strict_types=1);

return [
    'product_gallery_canary' => [
        'nav_label' => 'PG Canary',
        'title' => 'Product Gallery Canary',
        'section_title' => 'Create product canary project',
        'section_description' => 'Create Content Project + product article shell. Operator chooses 2-3 original images from Media Library (ID). No AI image generation.',
        'site_label' => 'Domain / site',
        'title_label' => 'Product title',
        'media_ids_label' => 'Original SeoMedia IDs',
        'media_ids_hint' => 'Example: 101,102,103 - minimum 2 IDs from Media Library.',
        'requirements_label' => 'Input requirements',
        'required_heading' => 'Required',
        'optional_heading' => 'Optional',
        'create_failed_title' => 'Canary creation failed',
        'fixture_ready_title' => 'Canary fixture is ready',
        'fixture_ready_body' => 'Article #:id - open editor to run Product Gallery modal.',
        'open_editor' => 'Open editor',
        'missing_article_title' => 'No canary article yet',
        'cleanup_failed_title' => 'Cleanup failed',
        'cleanup_done_title' => 'Discarded generated canary',
        'cleanup_done_body' => 'Discarded media: :discarded; originals kept: :originals',
    ],
];
