<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Durable observed WordPress post state — separate from Laravel desired/workflow.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('wordpress_article_links')) {
            return;
        }

        $schema->table('wordpress_article_links', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('wordpress_article_links', 'observed_post_status')) {
                $table->string('observed_post_status', 32)->nullable()->index();
            }
            if (! $schema->hasColumn('wordpress_article_links', 'observed_permalink')) {
                $table->string('observed_permalink', 500)->nullable();
            }
            if (! $schema->hasColumn('wordpress_article_links', 'observed_modified_at')) {
                $table->timestamp('observed_modified_at')->nullable();
            }
            if (! $schema->hasColumn('wordpress_article_links', 'observed_at')) {
                $table->timestamp('observed_at')->nullable()->index();
            }
            if (! $schema->hasColumn('wordpress_article_links', 'reconcile_status')) {
                $table->string('reconcile_status', 32)->nullable()->index();
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('wordpress_article_links')) {
            return;
        }

        $schema->table('wordpress_article_links', function (Blueprint $table) use ($schema): void {
            foreach ([
                'observed_post_status',
                'observed_permalink',
                'observed_modified_at',
                'observed_at',
                'reconcile_status',
            ] as $column) {
                if ($schema->hasColumn('wordpress_article_links', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
