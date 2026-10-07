<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Three-state Topic candidacy override on Group membership.
 *
 * NULL  = automatic (eligible iff Focus Article)
 * true  = FORCE_ALLOW (eligible without Focus)
 * false = FORCE_BLOCK (never automatic Topic anchor)
 *
 * Backfill: old is_topic_candidate=false → override=false;
 * old true stays NULL (was default, not user FORCE_ALLOW).
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_keyword_group_keywords')) {
            return;
        }
        if ($schema->hasColumn('seo_keyword_group_keywords', 'topic_candidate_override')) {
            return;
        }

        $schema->table('seo_keyword_group_keywords', function (Blueprint $table): void {
            $table->boolean('topic_candidate_override')->nullable();
        });

        if ($schema->hasColumn('seo_keyword_group_keywords', 'is_topic_candidate')) {
            DB::connection($this->connection)
                ->table('seo_keyword_group_keywords')
                ->where('is_topic_candidate', false)
                ->update(['topic_candidate_override' => false]);
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_keyword_group_keywords')) {
            return;
        }
        if (! $schema->hasColumn('seo_keyword_group_keywords', 'topic_candidate_override')) {
            return;
        }

        $schema->table('seo_keyword_group_keywords', function (Blueprint $table): void {
            $table->dropColumn('topic_candidate_override');
        });
    }
};
