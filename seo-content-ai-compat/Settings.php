<?php

declare(strict_types=1);

namespace App\Addons\SeoContentAi;

use Illuminate\Support\Str;

class Settings
{
    /**
     * Định nghĩa các trường dữ liệu mặc định cho Addon SEO Content AI.
     * * Hàm này được gọi tự động từ Model SiteService khi bản ghi mới được tạo.
     * Bạn có thể thêm bất kỳ logic nào ở đây (như random key, check config hệ thống, v.v.)
     */
    public function getDefaults(): array
    {
        return [
            // API Key duy nhất cho thực thể dịch vụ này trên website khách hàng
            'api_key' => 'seo_'.Str::random(32),

            // Token dùng để xác thực các webhook gửi về từ AI Engine
            'webhook_secret' => Str::uuid()->toString(),

            // Ngôn ngữ mặc định cho các bài viết được tạo
            'target_language' => 'vi',

            // Giới hạn số bài viết tối đa có thể tạo mỗi ngày cho site này
            'daily_limit' => 10,

            // Model AI sử dụng (gpt-4o, gpt-3.5-turbo, v.v.)
            'ai_model' => 'gpt-4o',

            // Tự động xuất bản bài viết sau khi tạo xong (true/false)
            'auto_publish' => false,

            // Tiền tố cho các chuyên mục được tạo tự động
            'category_prefix' => 'AI_',

            // Cấu hình database động theo site (auto = Docker production, manual = hosting lẻ)
            'db_config_type' => 'auto',
        ];
    }
}
