<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manual Index checklist timestamps (không phải Google index verification).
 * Nullable, không backfill — publish ≠ indexed.
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
            if (! Schema::connection($this->connection)->hasColumn('articles', 'indexed_at')) {
                $table->timestamp('indexed_at')->nullable()->after('last_synced_at');
            }

            if (! Schema::connection($this->connection)->hasColumn('articles', 'previous_indexed_at')) {
                $table->timestamp('previous_indexed_at')->nullable()->after('indexed_at');
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
            if (Schema::connection($this->connection)->hasColumn('articles', 'previous_indexed_at')) {
                $table->dropColumn('previous_indexed_at');
            }

            if (Schema::connection($this->connection)->hasColumn('articles', 'indexed_at')) {
                $table->dropColumn('indexed_at');
            }
        });
    }
};
