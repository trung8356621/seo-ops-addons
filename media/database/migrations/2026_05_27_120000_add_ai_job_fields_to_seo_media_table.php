<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_media')) {
            return;
        }

        // Legacy/end-state schema already dropped article_id + prompt_* (moved to meta).
        if (! $schema->hasColumn('seo_media', 'article_id')
            && ! $schema->hasColumn('seo_media', 'prompt_id')) {
            return;
        }

        $schema->table('seo_media', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('seo_media', 'prompt_id')) {
                $col = $table->unsignedBigInteger('prompt_id')->nullable()->index();
                if ($schema->hasColumn('seo_media', 'article_id')) {
                    $col->after('article_id');
                }
            }

            if (! $schema->hasColumn('seo_media', 'prompt_variables')) {
                $col = $table->json('prompt_variables')->nullable();
                if ($schema->hasColumn('seo_media', 'prompt_id')) {
                    $col->after('prompt_id');
                }
            }

            if (! $schema->hasColumn('seo_media', 'editor_block_id')) {
                $col = $table->string('editor_block_id', 64)->nullable()->index();
                if ($schema->hasColumn('seo_media', 'prompt_variables')) {
                    $col->after('prompt_variables');
                }
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_media')) {
            return;
        }

        $schema->table('seo_media', function (Blueprint $table) use ($schema): void {
            if ($schema->hasColumn('seo_media', 'editor_block_id')) {
                $table->dropColumn('editor_block_id');
            }

            if ($schema->hasColumn('seo_media', 'prompt_variables')) {
                $table->dropColumn('prompt_variables');
            }

            if ($schema->hasColumn('seo_media', 'prompt_id')) {
                $table->dropColumn('prompt_id');
            }
        });
    }
};
