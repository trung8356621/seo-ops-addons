<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Timestamp đáng tin cho «Lần cuối lưu» trên Content Project Run.
 * Không backfill từ updated_at — dữ liệu cũ để null.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('articles')) {
            return;
        }

        $schema->table('articles', function (Blueprint $table): void {
            if (! Schema::connection($this->connection)->hasColumn('articles', 'last_manual_saved_at')) {
                $table->timestamp('last_manual_saved_at')->nullable()->index()->after('published_at');
            }

            if (! Schema::connection($this->connection)->hasColumn('articles', 'last_synced_at')) {
                $table->timestamp('last_synced_at')->nullable()->index()->after('last_manual_saved_at');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('articles')) {
            return;
        }

        $schema->table('articles', function (Blueprint $table): void {
            if (Schema::connection($this->connection)->hasColumn('articles', 'last_synced_at')) {
                $table->dropColumn('last_synced_at');
            }

            if (Schema::connection($this->connection)->hasColumn('articles', 'last_manual_saved_at')) {
                $table->dropColumn('last_manual_saved_at');
            }
        });
    }
};
