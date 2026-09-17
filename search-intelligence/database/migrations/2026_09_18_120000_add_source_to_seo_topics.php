<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persist Topic provenance on seo_topics (manual Topics no longer need synthetic keyword seeds).
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_topics')) {
            return;
        }

        if (! $schema->hasColumn('seo_topics', 'source')) {
            $schema->table('seo_topics', function (Blueprint $table): void {
                $table->string('source', 16)->default('auto')->after('name');
                $table->index(['site_id', 'source'], 'seo_topics_site_source_idx');
            });
        }

        if (! $schema->hasTable('seo_topic_keywords')) {
            return;
        }

        // Backfill: Topics that currently have a manual seed membership → source=manual.
        $manualTopicIds = DB::connection($this->connection)
            ->table('seo_topic_keywords')
            ->where('is_seed', true)
            ->where('source', 'manual')
            ->distinct()
            ->pluck('topic_id')
            ->map(static fn ($id): int => (int) $id)
            ->filter(static fn (int $id): bool => $id > 0)
            ->values()
            ->all();

        if ($manualTopicIds !== []) {
            DB::connection($this->connection)
                ->table('seo_topics')
                ->whereIn('id', $manualTopicIds)
                ->update(['source' => 'manual']);
        }

        DB::connection($this->connection)
            ->table('seo_topics')
            ->whereNull('source')
            ->orWhere('source', '')
            ->update(['source' => 'auto']);
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_topics') || ! $schema->hasColumn('seo_topics', 'source')) {
            return;
        }

        $schema->table('seo_topics', function (Blueprint $table): void {
            $table->dropIndex('seo_topics_site_source_idx');
            $table->dropColumn('source');
        });
    }
};
