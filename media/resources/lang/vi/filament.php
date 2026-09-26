<?php

declare(strict_types=1);

return [
    'nav' => [
        'media_library' => 'Thu vien media',
        'image_optimization' => 'Toi uu hinh anh',
        'image_optimization_settings' => 'Cau hinh toi uu hinh anh',
        'image_processing' => 'Xu ly hinh anh',
        'image_processing_title' => 'Xu ly hinh anh AI',
    ],
    'media_runtime' => [
        'edit_image_title' => 'Chinh sua anh',
        'resize_complete_body' => 'Da cap nhat :count anh.',
        'resize_partial_body' => 'Thanh cong: :success. That bai: :failed',
    ],
    'watermark_settings' => [
        'select_domain' => 'Chon domain',
        'mode_watermark_and_optimize' => 'Dong dau + toi uu (WebP)',
        'mode_optimize_only' => 'Chi toi uu (bo qua file .webp)',
        'local_summary' => 'Local - dong dau: :watermarked · toi uu: :optimized · bo qua: :skipped.',
        'wordpress_summary' => 'WordPress - dong dau: :watermarked · toi uu: :optimized · bo qua: :skipped.',
        'wordpress_errors' => 'Loi WP: :count.',
        'wordpress_backup_note' => 'Anh WordPress goc duoc backup tren Laravel (lan dau).',
        'batch_completed' => 'Hoan tat xu ly hang loat',
        'open_visual_designer' => 'Mo visual designer',
        'apply_to_all_images' => 'Ap dung cho tat ca anh',
        'apply_modal_description' => 'Toi uu anh (resize, WebP theo "Cau hinh toi uu hinh anh"). Neu bat watermark: dong dau truoc khi toi uu. Che do chi toi uu se bo qua file da la .webp. Anh WordPress duoc backup tren Laravel khi chinh sua.',
    ],
];
