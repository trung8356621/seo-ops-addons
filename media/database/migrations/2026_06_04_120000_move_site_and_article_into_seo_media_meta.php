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
        if (! Schema::connection($this->connection)->hasTable('seo_media')
            || ! Schema::connection($this->connection)->hasTable('seo_media_meta')) {
            return;
        }

        $this->backfillSiteAndArticleMeta();

        Schema::connection($this->connection)->table('seo_media', function (Blueprint $table): void {
            if (Schema::connection($this->connection)->hasColumn('seo_media', 'article_id')) {
                try {
                    $table->dropForeign(['article_id']);
                } catch (Throwable) {
                    // ignore missing FK name differences
                }

                $table->dropColumn('article_id');
            }

            if (Schema::connection($this->connection)->hasColumn('seo_media', 'site_id')) {
                $table->dropColumn('site_id');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_media')) {
            return;
        }

        Schema::connection($this->connection)->table('seo_media', function (Blueprint $table): void {
            if (! Schema::connection($this->connection)->hasColumn('seo_media', 'site_id')) {
                $table->unsignedBigInteger('site_id')->nullable()->index()->first();
            }

            if (! Schema::connection($this->connection)->hasColumn('seo_media', 'article_id')) {
                $table->foreignId('article_id')
                    ->nullable()
                    ->constrained('articles')
                    ->nullOnDelete()
                    ->after('site_id');
            }
        });

        DB::connection($this->connection)
            ->table('seo_media')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                foreach ($rows as $row) {
                    $siteMeta = DB::connection($this->connection)
                        ->table('seo_media_meta')
                        ->where('media_id', (int) $row->id)
                        ->where('meta_key', 'site_id')
                        ->value('meta_value');

                    $articleMeta = DB::connection($this->connection)
                        ->table('seo_media_meta')
                        ->where('media_id', (int) $row->id)
                        ->where('meta_key', 'article_id')
                        ->value('meta_value');

                    $siteId = (int) $siteMeta;
                    $articleId = 0;
                    if (is_string($articleMeta) && $articleMeta !== '') {
                        $decoded = json_decode($articleMeta, true);
                        if (is_array($decoded) && $decoded !== []) {
                            $articleId = (int) ($decoded[0] ?? 0);
                        } else {
                            $articleId = (int) $articleMeta;
                        }
                    }

                    DB::connection($this->connection)
                        ->table('seo_media')
                        ->where('id', (int) $row->id)
                        ->update([
                            'site_id' => $siteId > 0 ? $siteId : null,
                            'article_id' => $articleId > 0 ? $articleId : null,
                        ]);
                }
            });
    }

    private function backfillSiteAndArticleMeta(): void
    {
        DB::connection($this->connection)
            ->table('seo_media')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                $upserts = [];
                $now = now();

                foreach ($rows as $row) {
                    $mediaId = (int) ($row->id ?? 0);
                    if ($mediaId <= 0) {
                        continue;
                    }

                    $siteId = (int) ($row->site_id ?? 0);
                    if ($siteId > 0) {
                        $upserts[] = [
                            'media_id' => $mediaId,
                            'meta_key' => 'site_id',
                            'meta_value' => (string) $siteId,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }

                    $articleId = (int) ($row->article_id ?? 0);
                    if ($articleId > 0) {
                        $upserts[] = [
                            'media_id' => $mediaId,
                            'meta_key' => 'article_id',
                            'meta_value' => json_encode([$articleId], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($upserts !== []) {
                    DB::connection($this->connection)
                        ->table('seo_media_meta')
                        ->upsert($upserts, ['media_id', 'meta_key'], ['meta_value', 'updated_at']);
                }
            });
    }
};

