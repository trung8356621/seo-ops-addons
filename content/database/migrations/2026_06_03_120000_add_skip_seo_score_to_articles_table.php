<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        Schema::connection($this->connection)->table('articles', function (Blueprint $table): void {
            if (! Schema::connection($this->connection)->hasColumn('articles', 'skip_seo_score')) {
                $table->boolean('skip_seo_score')->default(false)->after('seo_score');
            }
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('articles', function (Blueprint $table): void {
            if (Schema::connection($this->connection)->hasColumn('articles', 'skip_seo_score')) {
                $table->dropColumn('skip_seo_score');
            }
        });
    }
};
