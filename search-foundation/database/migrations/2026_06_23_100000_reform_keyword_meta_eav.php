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
        if (
            Schema::connection($this->connection)->hasTable('keyword_meta')
            && ! Schema::connection($this->connection)->hasColumn('keyword_meta', 'meta_key')
        ) {
            Schema::connection($this->connection)->drop('keyword_meta');
        }

        if (! Schema::connection($this->connection)->hasTable('keyword_meta')) {
            Schema::connection($this->connection)->create('keyword_meta', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('keyword_id')->index();
                $table->string('meta_key', 191);
                $table->longText('meta_value')->nullable();
                $table->timestamps();

                $table->unique(['keyword_id', 'meta_key'], 'keyword_meta_keyword_key_unique');
                $table->foreign('keyword_id')
                    ->references('id')
                    ->on('keywords')
                    ->cascadeOnDelete();
            });
        }

        $this->portKeywordSiteMeta();
        $this->portKeywordTagPivot();
        $this->portMainArticlePivot();

        if (Schema::connection($this->connection)->hasTable('keyword_site_meta')) {
            Schema::connection($this->connection)->dropIfExists('keyword_site_meta');
        }

        if (Schema::connection($this->connection)->hasTable('keyword_tag')) {
            Schema::connection($this->connection)->dropIfExists('keyword_tag');
        }

        if (Schema::connection($this->connection)->hasTable('tags')
            && ! Schema::connection($this->connection)->hasTable('keyword_tags')) {
            Schema::connection($this->connection)->rename('tags', 'keyword_tags');
        }
    }

    public function down(): void
    {
        if (Schema::connection($this->connection)->hasTable('keyword_tags')
            && ! Schema::connection($this->connection)->hasTable('tags')) {
            Schema::connection($this->connection)->rename('keyword_tags', 'tags');
        }

        if (! Schema::connection($this->connection)->hasTable('keyword_tag')) {
            Schema::connection($this->connection)->create('keyword_tag', function (Blueprint $table): void {
                $table->foreignId('keyword_id')->constrained('keywords')->cascadeOnDelete();
                $table->foreignId('tag_id')->constrained('tags')->cascadeOnDelete();
                $table->primary(['keyword_id', 'tag_id']);
            });
        }

        if (! Schema::connection($this->connection)->hasTable('keyword_site_meta')) {
            Schema::connection($this->connection)->create('keyword_site_meta', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('keyword_id')->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->text('target_url')->nullable();
                $table->unsignedInteger('search_volume')->nullable();
                $table->decimal('difficulty', 5, 2)->nullable();
                $table->json('metrics')->nullable();
                $table->timestamps();
                $table->unique(['keyword_id', 'site_id'], 'keyword_site_meta_keyword_site_unique');
            });
        }

        Schema::connection($this->connection)->dropIfExists('keyword_meta');
    }

    private function portKeywordSiteMeta(): void
    {
        if (! Schema::connection($this->connection)->hasTable('keyword_site_meta')) {
            return;
        }

        $rows = DB::connection($this->connection)->table('keyword_site_meta')->orderBy('id')->get();
        $insert = [];

        foreach ($rows as $row) {
            $keywordId = (int) ($row->keyword_id ?? 0);
            $siteId = (int) ($row->site_id ?? 0);
            if ($keywordId <= 0 || $siteId <= 0) {
                continue;
            }

            $targetUrl = trim((string) ($row->target_url ?? ''));
            if ($targetUrl !== '') {
                $insert[] = [
                    'keyword_id' => $keywordId,
                    'meta_key' => "site.{$siteId}.target_url",
                    'meta_value' => $targetUrl,
                ];
            }

            if ($row->search_volume !== null) {
                $insert[] = [
                    'keyword_id' => $keywordId,
                    'meta_key' => "site.{$siteId}.search_volume",
                    'meta_value' => (string) (int) $row->search_volume,
                ];
            }

            if ($row->difficulty !== null) {
                $insert[] = [
                    'keyword_id' => $keywordId,
                    'meta_key' => "site.{$siteId}.difficulty",
                    'meta_value' => (string) $row->difficulty,
                ];
            }

            $metrics = json_decode((string) ($row->metrics ?? ''), true);
            if (is_array($metrics)) {
                if (($metrics['rescrape_keep'] ?? false) === true) {
                    $insert[] = [
                        'keyword_id' => $keywordId,
                        'meta_key' => "site.{$siteId}.rescrape_keep",
                        'meta_value' => '1',
                    ];
                }
            }
        }

        $this->insertMetaRows($insert);
    }

    private function portKeywordTagPivot(): void
    {
        if (! Schema::connection($this->connection)->hasTable('keyword_tag')) {
            return;
        }

        $grouped = DB::connection($this->connection)
            ->table('keyword_tag')
            ->selectRaw('keyword_id, GROUP_CONCAT(tag_id ORDER BY tag_id) as tag_ids')
            ->groupBy('keyword_id')
            ->get();

        $insert = [];
        foreach ($grouped as $row) {
            $keywordId = (int) ($row->keyword_id ?? 0);
            if ($keywordId <= 0) {
                continue;
            }

            $tagIds = array_values(array_filter(
                array_map(static fn (string $id): int => (int) trim($id), explode(',', (string) ($row->tag_ids ?? ''))),
                static fn (int $id): bool => $id > 0,
            ));

            if ($tagIds === []) {
                continue;
            }

            $insert[] = [
                'keyword_id' => $keywordId,
                'meta_key' => 'tags',
                'meta_value' => json_encode($tagIds, JSON_THROW_ON_ERROR),
            ];
        }

        $this->insertMetaRows($insert);
    }

    private function portMainArticlePivot(): void
    {
        if (! Schema::connection($this->connection)->hasTable('article_keyword')) {
            return;
        }

        $rows = DB::connection($this->connection)
            ->table('article_keyword')
            ->where('is_main', true)
            ->orderBy('keyword_id')
            ->get(['keyword_id', 'article_id']);

        $insert = [];
        foreach ($rows as $row) {
            $keywordId = (int) ($row->keyword_id ?? 0);
            $articleId = (int) ($row->article_id ?? 0);
            if ($keywordId <= 0 || $articleId <= 0) {
                continue;
            }

            $insert[] = [
                'keyword_id' => $keywordId,
                'meta_key' => 'main_article_id',
                'meta_value' => (string) $articleId,
            ];
        }

        $this->insertMetaRows($insert);
    }

    /**
     * @param  list<array{keyword_id:int,meta_key:string,meta_value:string}>  $rows
     */
    private function insertMetaRows(array $rows): void
    {
        if ($rows === []) {
            return;
        }

        $now = now();
        foreach (array_chunk($rows, 200) as $chunk) {
            $payload = [];
            foreach ($chunk as $row) {
                $payload[] = [
                    'keyword_id' => $row['keyword_id'],
                    'meta_key' => $row['meta_key'],
                    'meta_value' => $row['meta_value'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }

            DB::connection($this->connection)->table('keyword_meta')->insertOrIgnore($payload);
        }
    }
};
