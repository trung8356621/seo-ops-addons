<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Forward cleanup: drop retired global keyword tag vocabulary.
 *
 * Topic tags SSOT is seo_topic_tags (see 2026_09_18_160000_rebuild_seo_topic_tags_vocabulary).
 * keyword_tags had zero product rows and no remaining runtime writers after Tag model removal.
 * Any residual keyword_meta.tags JSON ids are orphaned and intentionally not rewritten.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('keyword_tags')) {
            return;
        }

        // Pivot keyword_tag was already dropped by reform_keyword_meta_eav; belt-and-suspenders.
        if ($schema->hasTable('keyword_tag')) {
            $schema->drop('keyword_tag');
        }

        $rowCount = (int) DB::connection($this->connection)->table('keyword_tags')->count();
        if ($rowCount > 0) {
            // Obsolete vocabulary after Topic SSOT move — log then drop (no copy to seo_topic_tags).
            logger()->warning('drop_legacy_keyword_tags: discarding obsolete rows', [
                'row_count' => $rowCount,
            ]);
        }

        $schema->drop('keyword_tags');
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if ($schema->hasTable('keyword_tags')) {
            return;
        }

        $schema->create('keyword_tags', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->timestamps();
            $table->unique('name');
        });
    }
};
