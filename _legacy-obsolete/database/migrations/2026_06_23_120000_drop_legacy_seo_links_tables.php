<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('keyword_link')) {
            Schema::connection($this->connection)->dropIfExists('keyword_link');
        }

        if (Schema::connection($this->connection)->hasTable('seo_links')) {
            Schema::connection($this->connection)->dropIfExists('seo_links');
        }
    }

    public function down(): void
    {
        // Legacy tables are not recreated automatically.
    }
};
