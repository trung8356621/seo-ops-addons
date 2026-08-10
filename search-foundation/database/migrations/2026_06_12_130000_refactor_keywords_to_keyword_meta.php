<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('keywords')) {
            return;
        }

        if (! Schema::connection($this->connection)->hasTable('keyword_meta')) {
            Schema::connection($this->connection)->create('keyword_meta', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('keyword_id')->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->text('target_url')->nullable();
                $table->unsignedInteger('search_volume')->nullable();
                $table->decimal('difficulty', 5, 2)->nullable();
                $table->json('metrics')->nullable();
                $table->timestamps();

                $table->foreign('keyword_id')
                    ->references('id')
                    ->on('keywords')
                    ->cascadeOnDelete();

                $table->index(['keyword_id', 'site_id'], 'keyword_meta_keyword_site_index');
            });
        }

        if (Schema::connection($this->connection)->hasColumn('keywords', 'site_id')) {
            if (DB::connection($this->connection)->table('keyword_meta')->count() === 0) {
                $this->backfillKeywordMeta();
            }

            $this->mergeDuplicatePhrases();
            $this->mergeDuplicatePhrases();
            $this->dropLegacyKeywordColumns();
        } elseif (! $this->indexExists('keywords', 'keywords_phrase_unique')) {
            $this->mergeDuplicatePhrases();
            $this->ensurePhraseUniqueIndex();
        }
    }

    private function ensurePhraseUniqueIndex(): void
    {
        $this->mergeDuplicatePhrases();

        Schema::connection($this->connection)->table('keywords', function (Blueprint $table): void {
            if (! $this->indexExists('keywords', 'keywords_phrase_unique')) {
                $table->unique('phrase', 'keywords_phrase_unique');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasTable('keywords')) {
            return;
        }

        Schema::connection($this->connection)->table('keywords', function (Blueprint $table): void {
            if (! Schema::connection($this->connection)->hasColumn('keywords', 'user_id')) {
                $table->unsignedBigInteger('user_id')->nullable()->index()->after('id');
            }

            if (! Schema::connection($this->connection)->hasColumn('keywords', 'site_id')) {
                $table->unsignedBigInteger('site_id')->nullable()->index()->after('user_id');
            }

            if (! Schema::connection($this->connection)->hasColumn('keywords', 'search_volume')) {
                $table->unsignedInteger('search_volume')->nullable()->after('type');
            }

            if (! Schema::connection($this->connection)->hasColumn('keywords', 'difficulty')) {
                $table->decimal('difficulty', 5, 2)->nullable()->after('search_volume');
            }

            if (! Schema::connection($this->connection)->hasColumn('keywords', 'target_url')) {
                $table->text('target_url')->nullable()->after('difficulty');
            }

            if (! Schema::connection($this->connection)->hasColumn('keywords', 'metrics')) {
                $table->json('metrics')->nullable()->after('target_url');
            }
        });

        if (Schema::connection($this->connection)->hasTable('keyword_meta')) {
            $this->restoreKeywordColumnsFromMeta();

            Schema::connection($this->connection)->dropIfExists('keyword_meta');
        }

        Schema::connection($this->connection)->table('keywords', function (Blueprint $table): void {
            if ($this->indexExists('keywords', 'keywords_phrase_unique')) {
                $table->dropUnique('keywords_phrase_unique');
            }

            if (! $this->indexExists('keywords', 'keywords_site_id_phrase_index')) {
                $table->index(['site_id', 'phrase'], 'keywords_site_id_phrase_index');
            }

            if (! $this->indexExists('keywords', 'keywords_site_id_type_index')) {
                $table->index(['site_id', 'type'], 'keywords_site_id_type_index');
            }
        });
    }

    private function backfillKeywordMeta(): void
    {
        $rows = DB::connection($this->connection)
            ->table('keywords')
            ->orderBy('id')
            ->get([
                'id',
                'site_id',
                'target_url',
                'search_volume',
                'difficulty',
                'metrics',
                'created_at',
                'updated_at',
            ]);

        foreach ($rows as $row) {
            $siteId = (int) ($row->site_id ?? 0);
            if ($siteId <= 0) {
                continue;
            }

            $targetUrl = trim((string) ($row->target_url ?? ''));
            $exists = DB::connection($this->connection)
                ->table('keyword_meta')
                ->where('keyword_id', (int) $row->id)
                ->where('site_id', $siteId)
                ->where('target_url', $targetUrl !== '' ? $targetUrl : null)
                ->exists();

            if ($exists) {
                continue;
            }

            DB::connection($this->connection)->table('keyword_meta')->insert([
                'keyword_id' => (int) $row->id,
                'site_id' => $siteId,
                'target_url' => $targetUrl !== '' ? $targetUrl : null,
                'search_volume' => $row->search_volume,
                'difficulty' => $row->difficulty,
                'metrics' => $row->metrics,
                'created_at' => $row->created_at ?? now(),
                'updated_at' => $row->updated_at ?? now(),
            ]);
        }
    }

    private function mergeDuplicatePhrases(): void
    {
        $this->normalizeKeywordPhrases();

        DB::connection($this->connection)->statement('SET FOREIGN_KEY_CHECKS=0');

        try {
            while (true) {
                $duplicateGroup = DB::connection($this->connection)->selectOne(
                    'SELECT MIN(id) AS canonical_id
                     FROM keywords
                     GROUP BY (phrase COLLATE utf8mb4_unicode_ci)
                     HAVING COUNT(*) > 1
                     LIMIT 1',
                );

                if ($duplicateGroup === null) {
                    break;
                }

                $canonicalId = (int) $duplicateGroup->canonical_id;
                $canonicalPhrase = $this->normalizePhraseValue((string) DB::connection($this->connection)
                    ->table('keywords')
                    ->where('id', $canonicalId)
                    ->value('phrase'));

                DB::connection($this->connection)
                    ->table('keywords')
                    ->where('id', $canonicalId)
                    ->update(['phrase' => $canonicalPhrase]);

                $duplicateIds = DB::connection($this->connection)
                    ->table('keywords')
                    ->where('id', '!=', $canonicalId)
                    ->whereRaw(
                        'phrase COLLATE utf8mb4_unicode_ci = (SELECT phrase FROM keywords WHERE id = ?)',
                        [$canonicalId],
                    )
                    ->pluck('id')
                    ->map(static fn (mixed $id): int => (int) $id)
                    ->all();

                foreach ($duplicateIds as $duplicateId) {
                    $this->repointKeywordReferences($duplicateId, $canonicalId);
                    DB::connection($this->connection)->table('keywords')->where('id', $duplicateId)->delete();
                }
            }
        } finally {
            DB::connection($this->connection)->statement('SET FOREIGN_KEY_CHECKS=1');
        }
    }

    private function normalizeKeywordPhrases(): void
    {
        foreach (DB::connection($this->connection)->table('keywords')->orderBy('id')->get(['id', 'phrase']) as $row) {
            $normalized = $this->normalizePhraseValue((string) $row->phrase);

            if ($normalized === (string) $row->phrase) {
                continue;
            }

            DB::connection($this->connection)
                ->table('keywords')
                ->where('id', (int) $row->id)
                ->update(['phrase' => $normalized]);
        }
    }

    private function normalizePhraseValue(string $phrase): string
    {
        $phrase = html_entity_decode($phrase, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $phrase = str_replace("\xC2\xA0", ' ', $phrase);
        $phrase = preg_replace('/\s+/u', ' ', $phrase) ?? $phrase;

        return trim($phrase);
    }

    private function repointKeywordReferences(int $fromId, int $toId): void
    {
        if (Schema::connection($this->connection)->hasTable('article_keyword')) {
            $pivotRows = DB::connection($this->connection)
                ->table('article_keyword')
                ->where('keyword_id', $fromId)
                ->get(['id', 'article_id']);

            foreach ($pivotRows as $pivotRow) {
                $articleId = (int) $pivotRow->article_id;
                $conflict = DB::connection($this->connection)
                    ->table('article_keyword')
                    ->where('article_id', $articleId)
                    ->where('keyword_id', $toId)
                    ->exists();

                if ($conflict) {
                    DB::connection($this->connection)
                        ->table('article_keyword')
                        ->where('id', (int) $pivotRow->id)
                        ->delete();

                    continue;
                }

                DB::connection($this->connection)
                    ->table('article_keyword')
                    ->where('id', (int) $pivotRow->id)
                    ->update(['keyword_id' => $toId]);
            }
        }

        if (Schema::connection($this->connection)->hasTable('seo_article_links')) {
            DB::connection($this->connection)
                ->table('seo_article_links')
                ->where('keyword_id', $fromId)
                ->update(['keyword_id' => $toId]);
        }

        if (Schema::connection($this->connection)->hasTable('keyword_tag')) {
            $tagRows = DB::connection($this->connection)
                ->table('keyword_tag')
                ->where('keyword_id', $fromId)
                ->get(['tag_id']);

            foreach ($tagRows as $tagRow) {
                $tagId = (int) $tagRow->tag_id;
                $conflict = DB::connection($this->connection)
                    ->table('keyword_tag')
                    ->where('keyword_id', $toId)
                    ->where('tag_id', $tagId)
                    ->exists();

                DB::connection($this->connection)
                    ->table('keyword_tag')
                    ->where('keyword_id', $fromId)
                    ->where('tag_id', $tagId)
                    ->delete();

                if ($conflict) {
                    continue;
                }

                DB::connection($this->connection)
                    ->table('keyword_tag')
                    ->insert([
                        'keyword_id' => $toId,
                        'tag_id' => $tagId,
                    ]);
            }
        }

        DB::connection($this->connection)
            ->table('keywords')
            ->where('parent_id', $fromId)
            ->update(['parent_id' => $toId]);

        if (Schema::connection($this->connection)->hasTable('keyword_meta')) {
            $metaRows = DB::connection($this->connection)
                ->table('keyword_meta')
                ->where('keyword_id', $fromId)
                ->get();

            foreach ($metaRows as $metaRow) {
                $siteId = (int) $metaRow->site_id;
                $targetUrl = $metaRow->target_url;
                $conflict = DB::connection($this->connection)
                    ->table('keyword_meta')
                    ->where('keyword_id', $toId)
                    ->where('site_id', $siteId)
                    ->where('target_url', $targetUrl)
                    ->exists();

                if ($conflict) {
                    DB::connection($this->connection)
                        ->table('keyword_meta')
                        ->where('id', (int) $metaRow->id)
                        ->delete();

                    continue;
                }

                DB::connection($this->connection)
                    ->table('keyword_meta')
                    ->where('id', (int) $metaRow->id)
                    ->update(['keyword_id' => $toId]);
            }
        }
    }

    private function dropLegacyKeywordColumns(): void
    {
        $this->mergeDuplicatePhrases();

        Schema::connection($this->connection)->table('keywords', function (Blueprint $table): void {
            if ($this->indexExists('keywords', 'keywords_site_id_phrase_index')) {
                $table->dropIndex('keywords_site_id_phrase_index');
            }

            if ($this->indexExists('keywords', 'keywords_site_id_type_index')) {
                $table->dropIndex('keywords_site_id_type_index');
            }
        });

        Schema::connection($this->connection)->table('keywords', function (Blueprint $table): void {
            $columns = ['user_id', 'site_id', 'search_volume', 'difficulty', 'target_url', 'metrics'];
            foreach ($columns as $column) {
                if (Schema::connection($this->connection)->hasColumn('keywords', $column)) {
                    $table->dropColumn($column);
                }
            }
        });

        $this->ensurePhraseUniqueIndex();
    }

    private function restoreKeywordColumnsFromMeta(): void
    {
        $metaRows = DB::connection($this->connection)
            ->table('keyword_meta')
            ->orderBy('id')
            ->get();

        foreach ($metaRows as $metaRow) {
            DB::connection($this->connection)
                ->table('keywords')
                ->where('id', (int) $metaRow->keyword_id)
                ->update([
                    'site_id' => (int) $metaRow->site_id,
                    'target_url' => $metaRow->target_url,
                    'search_volume' => $metaRow->search_volume,
                    'difficulty' => $metaRow->difficulty,
                    'metrics' => $metaRow->metrics,
                ]);
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::connection($this->connection)->getConnection();
        $database = $connection->getDatabaseName();

        $result = DB::connection($this->connection)->select(
            'SELECT 1 FROM information_schema.statistics
             WHERE table_schema = ? AND table_name = ? AND index_name = ?
             LIMIT 1',
            [$database, $table, $indexName],
        );

        return $result !== [];
    }
};
