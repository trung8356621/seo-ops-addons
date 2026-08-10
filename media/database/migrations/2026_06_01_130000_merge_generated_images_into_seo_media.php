<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('seo_media')
            && ! $schema->hasColumn('seo_media', 'ai_generator')) {
            $schema->table('seo_media', function (Blueprint $table) use ($schema): void {
                $col = $table->string('ai_generator', 120)->nullable()->index();
                if ($schema->hasColumn('seo_media', 'source')) {
                    $col->after('source');
                }
            });
        }

        if (! $schema->hasTable('seo_generated_images')
            || ! $schema->hasTable('seo_media')) {
            return;
        }

        $hasSiteId = $schema->hasColumn('seo_media', 'site_id');
        $hasArticleId = $schema->hasColumn('seo_media', 'article_id');
        $hasWpAttachment = $schema->hasColumn('seo_media', 'wp_attachment_id');
        $hasAiGenerator = $schema->hasColumn('seo_media', 'ai_generator');

        DB::connection($this->connection)
            ->table('seo_generated_images')
            ->orderBy('id')
            ->chunkById(200, function ($rows) use ($hasSiteId, $hasArticleId, $hasWpAttachment, $hasAiGenerator): void {
                foreach ($rows as $row) {
                    $url = (string) ($row->url ?? '');
                    if ($url === '') {
                        continue;
                    }

                    $slug = trim((string) ($row->slug ?? ''));
                    if ($slug === '') {
                        $slug = 'legacy-ai-'.(int) $row->id;
                    }

                    $filename = basename((string) parse_url($url, PHP_URL_PATH));
                    if ($filename === '' || ! str_contains($filename, '.')) {
                        $filename = $slug.'.png';
                    }

                    $existsQuery = DB::connection($this->connection)
                        ->table('seo_media')
                        ->where('slug', $slug)
                        ->where('url', $url);

                    if ($hasSiteId) {
                        $existsQuery->where('site_id', (int) $row->site_id);
                    }

                    if ($existsQuery->exists()) {
                        continue;
                    }

                    $payload = [
                        'filename' => Str::limit($filename, 255, ''),
                        'slug' => Str::limit($slug, 255, ''),
                        'path' => '',
                        'url' => $url,
                        'source' => 'ai_prompt',
                        'created_at' => $row->created_at ?? now(),
                        'updated_at' => $row->updated_at ?? now(),
                    ];

                    if ($hasSiteId) {
                        $payload['site_id'] = (int) $row->site_id > 0 ? (int) $row->site_id : null;
                    }

                    if ($hasArticleId) {
                        $payload['article_id'] = isset($row->article_id) ? (int) $row->article_id : null;
                    }

                    if ($hasAiGenerator) {
                        $payload['ai_generator'] = (string) ($row->source ?? 'legacy_ai');
                    }

                    if ($hasWpAttachment) {
                        $payload['wp_attachment_id'] = isset($row->wp_attachment_id) ? (int) $row->wp_attachment_id : null;
                    }

                    DB::connection($this->connection)
                        ->table('seo_media')
                        ->insert($payload);
                }
            });

        Schema::connection($this->connection)->dropIfExists('seo_generated_images');
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_generated_images')) {
            Schema::connection($this->connection)->create('seo_generated_images', function (Blueprint $table) {
                $table->id();
                $table->unsignedBigInteger('site_id')->index();
                $table->foreignId('article_id')
                    ->nullable()
                    ->constrained('articles')
                    ->nullOnDelete();
                $table->string('slug', 255)->index();
                $table->text('url');
                $table->string('alt', 500)->nullable();
                $table->string('title', 500)->nullable();
                $table->string('source', 64)->default('ai');
                $table->unsignedBigInteger('wp_attachment_id')->nullable()->index();
                $table->timestamps();
            });
        }

        if (Schema::connection($this->connection)->hasTable('seo_media')
            && Schema::connection($this->connection)->hasColumn('seo_media', 'ai_generator')) {
            Schema::connection($this->connection)->table('seo_media', function (Blueprint $table) {
                $table->dropColumn('ai_generator');
            });
        }
    }
};
