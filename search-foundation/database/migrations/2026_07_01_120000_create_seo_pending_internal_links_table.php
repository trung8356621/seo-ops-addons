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
        Schema::connection($this->connection)->create('seo_pending_internal_links', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->unsignedBigInteger('source_article_id')->index();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->string('anchor_phrase', 500);
            $table->string('placeholder_hash', 16)->unique();
            $table->enum('status', ['pending', 'resolved'])->default('pending')->index();
            $table->string('resolved_target_url', 2048)->nullable();
            $table->unsignedBigInteger('resolved_target_article_id')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['keyword_id', 'status'], 'seo_pending_internal_links_keyword_status_idx');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_pending_internal_links');
    }
};
