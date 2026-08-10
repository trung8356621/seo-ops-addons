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
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('keywords')) {
            $schema->table('keywords', function (Blueprint $table) use ($schema): void {
                if (! $schema->hasColumn('keywords', 'type')) {
                    $col = $table->string('type', 50)
                        ->default('focus')
                        ->comment('focus: Từ khóa SEO, internal: Anchor text internal link');
                    if ($schema->hasColumn('keywords', 'phrase')) {
                        $col->after('phrase');
                    }
                }

                if (! $schema->hasColumn('keywords', 'target_url')) {
                    $col = $table->text('target_url')
                        ->nullable()
                        ->comment('URL đích mặc định nếu là internal link');
                    if ($schema->hasColumn('keywords', 'difficulty')) {
                        $col->after('difficulty');
                    }
                }

                if ($schema->hasColumn('keywords', 'site_id')
                    && $schema->hasColumn('keywords', 'type')) {
                    try {
                        $table->index(['site_id', 'type']);
                    } catch (\Throwable) {
                        // Index may already exist on legacy schema.
                    }
                }
            });
        }

        if ($schema->hasTable('article_keyword')
            && ! $schema->hasColumn('article_keyword', 'is_main')) {
            $schema->table('article_keyword', function (Blueprint $table) use ($schema): void {
                $col = $table->boolean('is_main')
                    ->default(false)
                    ->comment('Từ khóa chính của bài viết');
                if ($schema->hasColumn('article_keyword', 'weight')) {
                    $col->after('weight');
                }
            });
        }

        if ($schema->hasTable('seo_article_links')
            && ! $schema->hasColumn('seo_article_links', 'keyword_id')) {
            $schema->table('seo_article_links', function (Blueprint $table) use ($schema): void {
                $col = $table->unsignedBigInteger('keyword_id')->nullable()->index();
                if ($schema->hasColumn('seo_article_links', 'article_id')) {
                    $col->after('article_id');
                }

                $table->foreign('keyword_id')
                    ->references('id')
                    ->on('keywords')
                    ->nullOnDelete();
            });
        }

        if ($schema->hasTable('keywords') && $schema->hasColumn('keywords', 'type')) {
            DB::connection($this->connection)->table('keywords')->whereNull('type')->update(['type' => 'focus']);
        }

        if ($schema->hasTable('article_keyword') && $schema->hasColumn('article_keyword', 'is_main')) {
            DB::connection($this->connection)->table('article_keyword')
                ->where(function ($query): void {
                    $query->where('weight', '>=', 1)
                        ->orWhereNull('weight');
                })
                ->where('is_main', false)
                ->update(['is_main' => true]);
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('seo_article_links') && $schema->hasColumn('seo_article_links', 'keyword_id')) {
            $schema->table('seo_article_links', function (Blueprint $table): void {
                $table->dropForeign(['keyword_id']);
                $table->dropColumn('keyword_id');
            });
        }

        if ($schema->hasTable('article_keyword') && $schema->hasColumn('article_keyword', 'is_main')) {
            $schema->table('article_keyword', function (Blueprint $table): void {
                $table->dropColumn('is_main');
            });
        }

        if ($schema->hasTable('keywords')) {
            $schema->table('keywords', function (Blueprint $table) use ($schema): void {
                if ($schema->hasColumn('keywords', 'type')) {
                    try {
                        $table->dropIndex(['site_id', 'type']);
                    } catch (\Throwable) {
                    }
                }

                $drop = [];
                foreach (['type', 'target_url'] as $column) {
                    if ($schema->hasColumn('keywords', $column)) {
                        $drop[] = $column;
                    }
                }
                if ($drop !== []) {
                    $table->dropColumn($drop);
                }
            });
        }
    }
};
