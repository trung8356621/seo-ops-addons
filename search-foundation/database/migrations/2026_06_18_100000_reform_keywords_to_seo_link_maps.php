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
        if (! Schema::connection($this->connection)->hasTable('keywords')) {
            return;
        }

        // Legacy/populated DB: never wipe keyword inventory. Only ensure seo_link_maps exists.
        $keywordCount = (int) DB::connection($this->connection)->table('keywords')->count();
        if ($keywordCount > 0) {
            $this->ensureSeoLinkMapsTable();

            return;
        }

        Schema::connection($this->connection)->disableForeignKeyConstraints();

        if (Schema::connection($this->connection)->hasTable('keyword_link')) {
            Schema::connection($this->connection)->drop('keyword_link');
        }

        if (Schema::connection($this->connection)->hasTable('keyword_tag')) {
            DB::connection($this->connection)->table('keyword_tag')->truncate();
        }

        if (Schema::connection($this->connection)->hasTable('article_keyword')) {
            DB::connection($this->connection)->table('article_keyword')->truncate();
        }

        DB::connection($this->connection)->table('keywords')->update(['parent_id' => null]);
        DB::connection($this->connection)->table('keywords')->truncate();

        if (Schema::connection($this->connection)->hasTable('seo_link_maps')) {
            Schema::connection($this->connection)->drop('seo_link_maps');
        }

        $this->ensureSeoLinkMapsTable();

        Schema::connection($this->connection)->enableForeignKeyConstraints();
    }

    public function down(): void
    {
        Schema::connection($this->connection)->disableForeignKeyConstraints();

        Schema::connection($this->connection)->dropIfExists('seo_link_maps');

        if (! Schema::connection($this->connection)->hasTable('keyword_link')) {
            Schema::connection($this->connection)->create('keyword_link', function (Blueprint $table): void {
                $table->unsignedBigInteger('keyword_id');
                $table->unsignedBigInteger('link_id');
                $table->unsignedInteger('search_volume')->nullable();
                $table->unsignedInteger('difficulty')->nullable();
                $table->json('metrics')->nullable();
                $table->timestamps();

                $table->primary(['keyword_id', 'link_id']);
                $table->foreign('keyword_id')->references('id')->on('keywords')->cascadeOnDelete();
                $table->foreign('link_id')->references('id')->on('seo_links')->cascadeOnDelete();
            });
        }

        Schema::connection($this->connection)->enableForeignKeyConstraints();
    }

    private function ensureSeoLinkMapsTable(): void
    {
        if (Schema::connection($this->connection)->hasTable('seo_link_maps')) {
            return;
        }

        Schema::connection($this->connection)->create('seo_link_maps', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('keyword_id')->constrained('keywords')->cascadeOnDelete();
            $table->unsignedBigInteger('source_article_id')->index();
            $table->unsignedBigInteger('target_article_id')->nullable()->index();
            $table->string('target_external_url')->nullable();
            $table->text('anchor_text');
            $table->text('context_before')->nullable();
            $table->text('context_after')->nullable();
            $table->enum('link_type', ['internal', 'external', 'wiki_trust'])->default('internal')->index();
            $table->enum('status', ['active', 'needs_audit', 'ignored', 'broken'])->default('active')->index();
            $table->timestamps();

            $table->index(['source_article_id', 'keyword_id'], 'seo_link_maps_source_keyword_index');
        });
    }
};
