<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical optimistic document version for article body writes.
 * Not derived from updated_at / seo_article_revisions.id.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('articles')) {
            return;
        }

        if ($schema->hasColumn('articles', 'document_version')) {
            return;
        }

        $schema->table('articles', function (Blueprint $table): void {
            $after = Schema::connection($this->connection)->hasColumn('articles', 'last_ai_content_at')
                ? 'last_ai_content_at'
                : (Schema::connection($this->connection)->hasColumn('articles', 'last_manual_saved_at')
                    ? 'last_manual_saved_at'
                    : 'updated_at');
            $table->unsignedBigInteger('document_version')->default(1)->after($after);
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('articles') || ! $schema->hasColumn('articles', 'document_version')) {
            return;
        }

        $schema->table('articles', function (Blueprint $table): void {
            $table->dropColumn('document_version');
        });
    }
};
