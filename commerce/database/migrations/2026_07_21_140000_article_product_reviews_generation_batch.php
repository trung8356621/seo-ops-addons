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
            if (! $schema->hasColumn('article_product_reviews', 'generation_batch_id')) {
                $table->string('generation_batch_id', 64)->nullable()->index()->after('idempotency_key');
            }
            if (! $schema->hasColumn('article_product_reviews', 'retry_count')) {
                $table->unsignedInteger('retry_count')->default(0)->after('publish_attempts');
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
            if ($schema->hasColumn('article_product_reviews', 'generation_batch_id')) {
                $table->dropColumn('generation_batch_id');
            }
            if ($schema->hasColumn('article_product_reviews', 'retry_count')) {
                $table->dropColumn('retry_count');
            }
        });
    }
};
