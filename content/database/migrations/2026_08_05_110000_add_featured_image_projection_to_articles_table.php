<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Canonical featured-image projection for Article List (DB-only, no WP HTTP on GET).
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

        $schema->table('articles', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('articles', 'featured_thumb_url')) {
                $table->string('featured_thumb_url', 2048)->nullable();
            }
            if (! $schema->hasColumn('articles', 'featured_media_id')) {
                $table->unsignedBigInteger('featured_media_id')->nullable()->index();
            }
            if (! $schema->hasColumn('articles', 'featured_image_status')) {
                // available | absent | unknown (null = unknown / not backfilled)
                $table->string('featured_image_status', 16)->nullable()->index();
            }
            if (! $schema->hasColumn('articles', 'featured_image_source')) {
                $table->string('featured_image_source', 64)->nullable();
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('articles')) {
            return;
        }

        $schema->table('articles', function (Blueprint $table) use ($schema): void {
            foreach (['featured_image_source', 'featured_image_status', 'featured_media_id', 'featured_thumb_url'] as $column) {
                if ($schema->hasColumn('articles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
