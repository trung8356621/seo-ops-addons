<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('omi_seo_ai');

        if ($schema->hasTable('article_product_reviews')) {
            $schema->table('article_product_reviews', function (Blueprint $table) use ($schema): void {
                if (! $schema->hasColumn('article_product_reviews', 'synced_at')) {
                    $table->timestamp('synced_at')->nullable()->after('published_at');
                }
            });

            $map = [
                'draft' => 'pending',
                'pending_article' => 'pending',
                'pending_publish' => 'pending',
                'scheduled' => 'pending',
                'publishing' => 'syncing',
                'failed_dispatch' => 'failed',
            ];

            foreach ($map as $from => $to) {
                DB::connection('omi_seo_ai')
                    ->table('article_product_reviews')
                    ->where('status', $from)
                    ->update(['status' => $to]);
            }

            DB::connection('omi_seo_ai')
                ->table('article_product_reviews')
                ->where('status', 'published')
                ->update([
                    'status' => 'reviewed',
                    'synced_at' => DB::raw('COALESCE(synced_at, published_at, NOW())'),
                ]);
        }

        // automation_rules.visibility / classification: đã nằm trong core schema + data migration.
        // Không ghi SEO DB nữa.
    }

    public function down(): void
    {
        $schema = Schema::connection('omi_seo_ai');

        if ($schema->hasTable('article_product_reviews')
            && $schema->hasColumn('article_product_reviews', 'synced_at')
        ) {
            $schema->table('article_product_reviews', function (Blueprint $table): void {
                $table->dropColumn('synced_at');
            });
        }
    }
};
