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

        $this->createSeoLinksTable();
        $this->createKeywordLinkTable();
        $this->portKeywordMetaToLinkGraph();
        $this->portSeoArticleLinksToLinkGraph();
        $this->dropLegacyTables();
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('keyword_link');
        Schema::connection($this->connection)->dropIfExists('seo_links');
    }

    private function createSeoLinksTable(): void
    {
        if (Schema::connection($this->connection)->hasTable('seo_links')) {
            return;
        }

        Schema::connection($this->connection)->create('seo_links', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->text('url');
            $table->enum('type', ['internal', 'external'])->index();
            $table->unsignedBigInteger('article_id')->nullable()->index();
            $table->unsignedBigInteger('source_article_id')->nullable()->index();
            $table->boolean('is_nofollow')->default(false);
            $table->timestamps();

            $table->index(['site_id', 'source_article_id', 'type'], 'seo_links_site_source_type_index');
        });

        DB::connection($this->connection)->statement(
            'ALTER TABLE seo_links ADD INDEX seo_links_site_id_url_index (site_id, url(255))',
        );
    }

    private function createKeywordLinkTable(): void
    {
        if (Schema::connection($this->connection)->hasTable('keyword_link')) {
            return;
        }

        Schema::connection($this->connection)->create('keyword_link', function (Blueprint $table): void {
            $table->unsignedBigInteger('keyword_id');
            $table->unsignedBigInteger('link_id');
            $table->unsignedInteger('search_volume')->nullable();
            $table->unsignedInteger('difficulty')->nullable();
            $table->json('metrics')->nullable();
            $table->timestamps();

            $table->primary(['keyword_id', 'link_id']);
            $table->foreign('keyword_id')->references('id')->on('keywords')->cascadeOnDelete();
            $table->foreign('link_id')->references('id')->on('seo_links')->cascadeOnDelete();
        });
    }

    private function portKeywordMetaToLinkGraph(): void
    {
        if (! Schema::connection($this->connection)->hasTable('keyword_meta')) {
            return;
        }

        $rows = DB::connection($this->connection)
            ->table('keyword_meta')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $siteId = (int) ($row->site_id ?? 0);
            $keywordId = (int) ($row->keyword_id ?? 0);
            if ($siteId <= 0 || $keywordId <= 0) {
                continue;
            }

            $targetUrl = trim((string) ($row->target_url ?? ''));
            if ($targetUrl === '') {
                continue;
            }

            $linkId = $this->upsertSeoLink(
                siteId: $siteId,
                url: $targetUrl,
                type: 'internal',
                sourceArticleId: null,
                targetArticleId: null,
                isNofollow: false,
            );

            $this->upsertKeywordLinkPivot(
                keywordId: $keywordId,
                linkId: $linkId,
                searchVolume: $row->search_volume,
                difficulty: $row->difficulty,
                metrics: $row->metrics,
            );
        }
    }

    private function portSeoArticleLinksToLinkGraph(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_article_links')) {
            return;
        }

        $rows = DB::connection($this->connection)
            ->table('seo_article_links')
            ->orderBy('id')
            ->get();

        foreach ($rows as $row) {
            $article = DB::connection($this->connection)
                ->table('articles')
                ->where('id', (int) $row->article_id)
                ->first(['id', 'site_id']);

            if ($article === null) {
                continue;
            }

            $siteId = (int) ($article->site_id ?? 0);
            $url = trim((string) ($row->url ?? ''));
            if ($siteId <= 0 || $url === '') {
                continue;
            }

            $linkId = $this->upsertSeoLink(
                siteId: $siteId,
                url: $url,
                type: (string) ($row->type ?? 'internal'),
                sourceArticleId: (int) $row->article_id,
                targetArticleId: null,
                isNofollow: (bool) ($row->is_nofollow ?? false),
            );

            if ($row->keyword_id !== null && (int) $row->keyword_id > 0) {
                $this->upsertKeywordLinkPivot(
                    keywordId: (int) $row->keyword_id,
                    linkId: $linkId,
                    searchVolume: null,
                    difficulty: null,
                    metrics: null,
                );
            }
        }
    }

    private function dropLegacyTables(): void
    {
        Schema::connection($this->connection)->dropIfExists('keyword_meta');
        Schema::connection($this->connection)->dropIfExists('seo_article_links');
    }

    private function upsertSeoLink(
        int $siteId,
        string $url,
        string $type,
        ?int $sourceArticleId,
        ?int $targetArticleId,
        bool $isNofollow,
    ): int {
        $query = DB::connection($this->connection)
            ->table('seo_links')
            ->where('site_id', $siteId)
            ->where('url', $url)
            ->where('type', $type);

        if ($sourceArticleId !== null) {
            $query->where('source_article_id', $sourceArticleId);
        } else {
            $query->whereNull('source_article_id');
        }

        $existingId = $query->value('id');
        if ($existingId !== null) {
            DB::connection($this->connection)
                ->table('seo_links')
                ->where('id', (int) $existingId)
                ->update([
                    'is_nofollow' => $isNofollow,
                    'article_id' => $targetArticleId,
                    'updated_at' => now(),
                ]);

            return (int) $existingId;
        }

        return (int) DB::connection($this->connection)->table('seo_links')->insertGetId([
            'site_id' => $siteId,
            'url' => $url,
            'type' => $type,
            'article_id' => $targetArticleId,
            'source_article_id' => $sourceArticleId,
            'is_nofollow' => $isNofollow,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function upsertKeywordLinkPivot(
        int $keywordId,
        int $linkId,
        mixed $searchVolume,
        mixed $difficulty,
        mixed $metrics,
    ): void {
        $existing = DB::connection($this->connection)
            ->table('keyword_link')
            ->where('keyword_id', $keywordId)
            ->where('link_id', $linkId)
            ->first();

        $mergedMetrics = $metrics;
        if ($metrics !== null && $existing !== null && $existing->metrics !== null) {
            $decoded = json_decode((string) $existing->metrics, true);
            $incoming = json_decode((string) $metrics, true);
            if (is_array($decoded) && is_array($incoming)) {
                $mergedMetrics = json_encode(array_merge($decoded, $incoming), JSON_UNESCAPED_UNICODE);
            }
        } elseif (is_array($metrics)) {
            $mergedMetrics = json_encode($metrics, JSON_UNESCAPED_UNICODE);
        }

        $payload = array_filter([
            'search_volume' => $searchVolume !== null ? (int) $searchVolume : null,
            'difficulty' => $difficulty !== null ? (int) round((float) $difficulty) : null,
            'metrics' => $mergedMetrics,
            'updated_at' => now(),
        ], static fn (mixed $value): bool => $value !== null);

        if ($existing === null) {
            DB::connection($this->connection)->table('keyword_link')->insert(array_merge([
                'keyword_id' => $keywordId,
                'link_id' => $linkId,
                'created_at' => now(),
            ], $payload));

            return;
        }

        DB::connection($this->connection)
            ->table('keyword_link')
            ->where('keyword_id', $keywordId)
            ->where('link_id', $linkId)
            ->update($payload);
    }
};
