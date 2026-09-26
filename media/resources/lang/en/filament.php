<?php

declare(strict_types=1);

return [
    'nav' => [
        'media_library' => 'Media library',
        'image_optimization' => 'Image optimization',
        'image_optimization_settings' => 'Image optimization settings',
        'image_processing' => 'Image processing',
        'image_processing_title' => 'AI image processing',
    ],
    'media_runtime' => [
        'edit_image_title' => 'Edit image',
        'resize_complete_body' => ':count images were updated.',
        'resize_partial_body' => 'Success: :success. Failed: :failed',
    ],
    'watermark_settings' => [
        'select_domain' => 'Select domain',
        'mode_watermark_and_optimize' => 'Watermark + optimize (WebP)',
        'mode_optimize_only' => 'Optimize only (skip .webp files)',
        'local_summary' => 'Local - watermarked: :watermarked · optimized: :optimized · skipped: :skipped.',
        'wordpress_summary' => 'WordPress - watermarked: :watermarked · optimized: :optimized · skipped: :skipped.',
        'wordpress_errors' => 'WP errors: :count.',
        'wordpress_backup_note' => 'Original WordPress images are backed up on Laravel (first run).',
        'batch_completed' => 'Batch processing completed',
        'open_visual_designer' => 'Open visual designer',
        'apply_to_all_images' => 'Apply to all images',
        'apply_modal_description' => 'Optimize images (resize, WebP based on "Image optimization settings"). If watermark is enabled: apply watermark before optimization. Optimize-only mode skips files already in .webp format. WordPress images are backed up on Laravel when edited.',
    ],
];
