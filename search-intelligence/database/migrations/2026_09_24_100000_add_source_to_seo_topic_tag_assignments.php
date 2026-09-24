<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assignment provenance: manual | ai.
 * Existing rows default to manual (manual intent wins historically).
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_topic_tag_assignments')) {
            return;
        }
        if ($schema->hasColumn('seo_topic_tag_assignments', 'source')) {
            return;
        }

        $schema->table('seo_topic_tag_assignments', function (Blueprint $table): void {
            $table->string('source', 16)->default('manual')->after('tag_id');
            $table->index(['source'], 'seo_topic_tag_assignments_source_idx');
        });

        DB::connection($this->connection)
            ->table('seo_topic_tag_assignments')
            ->whereNull('source')
            ->orWhere('source', '')
            ->update(['source' => 'manual']);
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_topic_tag_assignments')) {
            return;
        }
        if (! $schema->hasColumn('seo_topic_tag_assignments', 'source')) {
            return;
        }

        $schema->table('seo_topic_tag_assignments', function (Blueprint $table): void {
            $table->dropIndex('seo_topic_tag_assignments_source_idx');
            $table->dropColumn('source');
        });
    }
};
