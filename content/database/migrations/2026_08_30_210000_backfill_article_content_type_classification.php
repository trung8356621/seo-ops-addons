<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical Article classification:
 * - article_meta.content_type = post|page|product
 * - article_meta.wp_is_term = 0|1
 * - articles.parent_id = NULL (non-term) | 0 (root term) | local parent article id
 *
 * Backfills from existing articles.type / wp_entity / wp_post_type / wp_parent_id.
 * No WordPress / network calls. Idempotent (upsert by meta_key; safe retry).
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    private const CHUNK = 500;

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasColumn('articles', 'parent_id')) {
            $schema->table('articles', function (Blueprint $table): void {
                $table->unsignedBigInteger('parent_id')
                    ->nullable()
                    ->after('site_id')
                    ->comment('Term hierarchy only: NULL=non-term, 0=root, else local parent article id');
                $table->index('parent_id');
            });
        }

        $this->backfillClassification();
        $this->backfillParentIds();
    }

    public function down(): void
    {
        $db = DB::connection($this->connection);

        $db->table('article_meta')
            ->whereIn('meta_key', ['content_type', 'wp_is_term'])
            ->delete();

        $schema = Schema::connection($this->connection);
        if ($schema->hasColumn('articles', 'parent_id')) {
            $schema->table('articles', function (Blueprint $table): void {
                $table->dropIndex(['parent_id']);
                $table->dropColumn('parent_id');
            });
        }
    }

    private function backfillClassification(): void
    {
        $db = DB::connection($this->connection);
        $lastId = 0;

        while (true) {
            $rows = $db->table('articles')
                ->where('id', '>', $lastId)
                ->orderBy('id')
                ->limit(self::CHUNK)
                ->get(['id', 'type']);

            if ($rows->isEmpty()) {
                break;
            }

            $ids = $rows->pluck('id')->map(static fn ($id): int => (int) $id)->all();
            $metas = $db->table('article_meta')
                ->whereIn('article_id', $ids)
                ->whereIn('meta_key', ['wp_post_type', 'wp_entity', 'wp_taxonomy', 'content_type', 'wp_is_term'])
                ->get(['article_id', 'meta_key', 'meta_value'])
                ->groupBy('article_id');

            $now = now();
            $upserts = [];

            foreach ($rows as $row) {
                $lastId = (int) $row->id;
                $byKey = [];
                foreach ($metas->get($row->id, collect()) as $meta) {
                    $byKey[(string) $meta->meta_key] = (string) ($meta->meta_value ?? '');
                }

                // Idempotent: skip rows that already have both canonical keys.
                if (
                    isset($byKey['content_type']) && $byKey['content_type'] !== ''
                    && isset($byKey['wp_is_term']) && $byKey['wp_is_term'] !== ''
                ) {
                    continue;
                }

                $inferred = $this->infer(
                    (string) ($row->type ?? ''),
                    $byKey['wp_post_type'] ?? '',
                    $byKey['wp_entity'] ?? '',
                    $byKey['wp_taxonomy'] ?? '',
                );

                $upserts[] = [
                    'article_id' => $lastId,
                    'meta_key' => 'content_type',
                    'meta_value' => $inferred['content_type'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
                $upserts[] = [
                    'article_id' => $lastId,
                    'meta_key' => 'wp_is_term',
                    'meta_value' => $inferred['wp_is_term'] ? '1' : '0',
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if (($byKey['wp_post_type'] ?? '') === '' && $inferred['wp_post_type'] !== '') {
                    $upserts[] = [
                        'article_id' => $lastId,
                        'meta_key' => 'wp_post_type',
                        'meta_value' => $inferred['wp_post_type'],
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }

            foreach (array_chunk($upserts, 200) as $chunk) {
                foreach ($chunk as $row) {
                    $exists = $db->table('article_meta')
                        ->where('article_id', $row['article_id'])
                        ->where('meta_key', $row['meta_key'])
                        ->exists();

                    if ($exists) {
                        $db->table('article_meta')
                            ->where('article_id', $row['article_id'])
                            ->where('meta_key', $row['meta_key'])
                            ->update([
                                'meta_value' => $row['meta_value'],
                                'updated_at' => $row['updated_at'],
                            ]);
                    } else {
                        $db->table('article_meta')->insert($row);
                    }
                }
            }

            // Non-term parent_id must be NULL.
            $nonTermIds = [];
            foreach ($rows as $row) {
                $byKey = [];
                foreach ($metas->get($row->id, collect()) as $meta) {
                    $byKey[(string) $meta->meta_key] = (string) ($meta->meta_value ?? '');
                }
                $inferred = $this->infer(
                    (string) ($row->type ?? ''),
                    $byKey['wp_post_type'] ?? '',
                    $byKey['wp_entity'] ?? '',
                    $byKey['wp_taxonomy'] ?? '',
                );
                if (! $inferred['wp_is_term']) {
                    $nonTermIds[] = (int) $row->id;
                }
            }
            if ($nonTermIds !== []) {
                $db->table('articles')->whereIn('id', $nonTermIds)->update(['parent_id' => null]);
            }
        }
    }

    private function backfillParentIds(): void
    {
        $db = DB::connection($this->connection);
        $lastId = 0;

        while (true) {
            $termRows = $db->table('articles as a')
                ->join('article_meta as is_term', function ($join): void {
                    $join->on('is_term.article_id', '=', 'a.id')
                        ->where('is_term.meta_key', '=', 'wp_is_term')
                        ->where('is_term.meta_value', '=', '1');
                })
                ->leftJoin('article_meta as wp_parent', function ($join): void {
                    $join->on('wp_parent.article_id', '=', 'a.id')
                        ->where('wp_parent.meta_key', '=', 'wp_parent_id');
                })
                ->where('a.id', '>', $lastId)
                ->orderBy('a.id')
                ->limit(self::CHUNK)
                ->get([
                    'a.id',
                    'a.site_id',
                    'a.parent_id',
                    'wp_parent.meta_value as wp_parent_id',
                ]);

            if ($termRows->isEmpty()) {
                break;
            }

            foreach ($termRows as $row) {
                $lastId = (int) $row->id;
                $wpParentId = $row->wp_parent_id;

                if ($wpParentId === null || $wpParentId === '') {
                    // Fail-closed root when wp_parent_id missing after term flag.
                    if ($row->parent_id === null) {
                        $db->table('articles')->where('id', $lastId)->update(['parent_id' => 0]);
                    }
                    continue;
                }

                $wpParent = (int) $wpParentId;
                if ($wpParent === 0) {
                    $db->table('articles')->where('id', $lastId)->update(['parent_id' => 0]);
                    continue;
                }

                // Resolve WP term parent id → local article id (same site, term entity).
                $parentArticleId = $db->table('articles as parent')
                    ->join('wordpress_article_links as wal', 'wal.article_id', '=', 'parent.id')
                    ->join('article_meta as parent_term', function ($join): void {
                        $join->on('parent_term.article_id', '=', 'parent.id')
                            ->where('parent_term.meta_key', '=', 'wp_is_term')
                            ->where('parent_term.meta_value', '=', '1');
                    })
                    ->where('parent.site_id', (int) $row->site_id)
                    ->where('wal.wp_post_id', $wpParent)
                    ->value('parent.id');

                if ($parentArticleId !== null) {
                    $db->table('articles')->where('id', $lastId)->update([
                        'parent_id' => (int) $parentArticleId,
                    ]);
                } elseif ($row->parent_id === null) {
                    // Parent not imported yet — keep 0 until a later retry/pass can resolve.
                    // Do not invent hierarchy from WP post_parent.
                    $db->table('articles')->where('id', $lastId)->update(['parent_id' => 0]);
                }
            }
        }
    }

    /**
     * @return array{content_type: string, wp_is_term: bool, wp_post_type: string}
     */
    private function infer(string $legacyType, string $wpPostType, string $wpEntity, string $wpTaxonomy): array
    {
        $legacyType = strtolower(trim($legacyType));
        $wpPostType = strtolower(trim($wpPostType));
        $wpEntity = strtolower(trim($wpEntity));
        $wpTaxonomy = strtolower(trim($wpTaxonomy));

        $isTerm = $wpEntity === 'term'
            || in_array($legacyType, ['category', 'product_category', 'product_cat'], true);

        if ($wpPostType === '') {
            $wpPostType = match (true) {
                $wpTaxonomy !== '' => $wpTaxonomy,
                $legacyType === 'product' => 'product',
                $legacyType === 'page' => 'page',
                $legacyType === 'category' => 'category',
                in_array($legacyType, ['product_category', 'product_cat'], true) => 'product_cat',
                default => 'post',
            };
        }

        $contentType = match (true) {
            $legacyType === 'page' || $wpPostType === 'page' => 'page',
            $legacyType === 'product' || $wpPostType === 'product' => 'product',
            in_array($legacyType, ['product_category', 'product_cat'], true)
                || in_array($wpPostType, ['product_cat', 'product_tag'], true)
                || $wpTaxonomy === 'product_cat' => 'product',
            $legacyType === 'category'
                || in_array($wpPostType, ['category', 'post_tag'], true)
                || $wpTaxonomy === 'category' => 'post',
            $wpPostType === 'landing_page' => 'page',
            $wpPostType === 'machine' => 'product',
            $wpPostType === 'portfolio' => 'post',
            default => 'post',
        };

        return [
            'content_type' => $contentType,
            'wp_is_term' => $isTerm,
            'wp_post_type' => $wpPostType,
        ];
    }
};
