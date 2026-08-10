<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Timestamp AI body persist — Last saved / manual-edit detection.
 * Không backfill từ updated_at.
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

        if ($schema->hasColumn('articles', 'last_ai_content_at')) {
            return;
        }

        $schema->table('articles', function (Blueprint $table): void {
            $after = Schema::connection($this->connection)->hasColumn('articles', 'last_synced_at')
                ? 'last_synced_at'
                : (Schema::connection($this->connection)->hasColumn('articles', 'last_manual_saved_at')
                    ? 'last_manual_saved_at'
                    : 'published_at');
            $table->timestamp('last_ai_content_at')->nullable()->index()->after($after);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('articles') || ! $schema->hasColumn('articles', 'last_ai_content_at')) {
            return;
        }

        $schema->table('articles', function (Blueprint $table): void {
            $table->dropColumn('last_ai_content_at');
        });
    }
};
