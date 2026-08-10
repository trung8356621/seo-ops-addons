<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        if (Schema::connection($this->connection)->hasTable('article_keyword')) {
            Schema::connection($this->connection)->dropIfExists('article_keyword');
        }
    }

    public function down(): void
    {
        // Legacy pivot is not recreated automatically.
    }
};
