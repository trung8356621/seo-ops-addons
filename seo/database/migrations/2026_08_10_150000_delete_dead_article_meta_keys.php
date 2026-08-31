<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Delete dead article_meta keys (no active writers/readers as SoT).
 * Idempotent: re-run deletes 0 rows after first pass.
 *
 * Deleted keys: seo_rank_math_score, seo_extracted_links, seo_outline_json,
 * seo_semantic_keywords, create_article_task_run, wp_post_title.
 *
 * Retained (active readers at time of this migration): seo_scoring_details, seo_title, wp_slug, wp_post_type.
 * Note: wp_post_content was also retained then; later deleted separately.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    /**
     * @var list<string>
     */
    private const DEAD_META_KEYS = [
        'seo_rank_math_score',
        'seo_extracted_links',
        'seo_outline_json',
        'seo_semantic_keywords',
        'create_article_task_run',
        'wp_post_title',
    ];

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('article_meta')) {
            return;
        }

        $db = DB::connection($this->connection);
        $placeholders = implode(', ', array_fill(0, count(self::DEAD_META_KEYS), '?'));

        $before = (int) $db->table('article_meta')
            ->whereIn('meta_key', self::DEAD_META_KEYS)
            ->count();

        // Count before delete logged for ops: {$before} rows matched.
        $deleted = $db->affectingStatement(
            "DELETE FROM article_meta WHERE meta_key IN ({$placeholders})",
            self::DEAD_META_KEYS,
        );

        // Optional echo for migrate --pretend / verbose ops visibility.
        if ($before > 0 || $deleted > 0) {
            echo "article_meta dead keys: matched={$before}, deleted={$deleted}\n";
        }
    }

    public function down(): void
    {
        // Irreversible data cleanup — no restore of deleted meta rows.
    }
};
