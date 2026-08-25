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

        if (! $schema->hasTable('seo_article_index_checks')) {
            $schema->create('seo_article_index_checks', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id')->index();
                $table->unsignedBigInteger('article_id')->index();
                $table->string('url', 2048);
                $table->string('status', 32)->index(); // indexed|not_indexed|unknown
                $table->string('effective_health', 32)->index(); // indexed|not_indexed|dropped|unknown
                $table->timestamp('checked_at')->index();
                $table->unsignedBigInteger('checked_by')->nullable()->index();
                $table->string('source', 32)->default('manual')->index(); // manual|gsc|other
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['article_id', 'checked_at']);
                $table->index(['site_id', 'checked_at']);
            });
        }

        if (! $schema->hasTable('seo_article_index_health')) {
            $schema->create('seo_article_index_health', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('article_id')->unique();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('canonical_url', 2048)->nullable();
                $table->string('current_status', 32)->index(); // indexed|not_indexed|dropped|unknown
                $table->string('previous_status', 32)->nullable();
                $table->timestamp('last_checked_at')->nullable()->index();
                $table->timestamp('last_indexed_at')->nullable();
                $table->timestamp('last_not_indexed_at')->nullable();
                $table->timestamps();

                $table->index(['site_id', 'current_status']);
                $table->index(['site_id', 'last_checked_at']);
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('seo_article_index_checks');
        $schema->dropIfExists('seo_article_index_health');
    }
};
