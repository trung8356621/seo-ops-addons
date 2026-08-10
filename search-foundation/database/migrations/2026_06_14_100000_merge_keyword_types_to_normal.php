<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        DB::connection($this->connection)
            ->table('keywords')
            ->whereIn('type', ['focus', 'internal'])
            ->update(['type' => 'normal']);

        DB::connection($this->connection)->statement(
            "ALTER TABLE `keywords` MODIFY `type` VARCHAR(50) NOT NULL DEFAULT 'normal' COMMENT 'normal|suggest|free'"
        );
    }

    public function down(): void
    {
        DB::connection($this->connection)
            ->table('keywords')
            ->where('type', 'normal')
            ->update(['type' => 'focus']);

        DB::connection($this->connection)->statement(
            "ALTER TABLE `keywords` MODIFY `type` VARCHAR(50) NOT NULL DEFAULT 'focus' COMMENT 'focus: Từ khóa SEO, internal: Anchor text internal link'"
        );
    }
};
