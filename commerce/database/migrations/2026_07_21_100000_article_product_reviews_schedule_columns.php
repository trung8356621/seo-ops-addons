<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        if (! $schema->hasTable('article_product_reviews')) {
            return;
        }

        $schema->table('article_product_reviews', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('article_product_reviews', 'scheduled_at')) {
                $table->timestamp('scheduled_at')->nullable()->after('published_at');
            }
            if (! $schema->hasColumn('article_product_reviews', 'publishing_started_at')) {
                $table->timestamp('publishing_started_at')->nullable()->after('scheduled_at');
            }
            if (! $schema->hasColumn('article_product_reviews', 'next_retry_at')) {
                $table->timestamp('next_retry_at')->nullable()->after('publishing_started_at');
            }
            if (! $schema->hasColumn('article_product_reviews', 'selected_delay_seconds')) {
                $table->unsignedInteger('selected_delay_seconds')->nullable()->after('next_retry_at');
            }
            if (! $schema->hasColumn('article_product_reviews', 'configured_max_delay_minutes')) {
                $table->unsignedInteger('configured_max_delay_minutes')->nullable()->after('selected_delay_seconds');
            }
            if (! $schema->hasColumn('article_product_reviews', 'publish_execution_id')) {
                $table->unsignedBigInteger('publish_execution_id')->nullable()->index()->after('configured_max_delay_minutes');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        if (! $schema->hasTable('article_product_reviews')) {
            return;
        }

        $schema->table('article_product_reviews', function (Blueprint $table) use ($schema): void {
            foreach ([
                'scheduled_at',
                'publishing_started_at',
                'next_retry_at',
                'selected_delay_seconds',
                'configured_max_delay_minutes',
                'publish_execution_id',
            ] as $column) {
                if ($schema->hasColumn('article_product_reviews', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
