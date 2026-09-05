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
        if ($schema->hasTable('link_resources')) {
            return;
        }

        $schema->create('link_resources', function (Blueprint $table): void {
            $table->id();
            $table->text('original_url');
            $table->string('normalized_url', 2048);
            $table->string('normalized_url_hash', 64);
            $table->string('domain', 255)->index();
            $table->string('title', 512)->nullable();
            $table->text('description')->nullable();
            // pending | fetched | failed | skipped — fetch not implemented this sprint
            $table->string('fetch_status', 32)->nullable();
            $table->timestamp('fetched_at')->nullable();
            // Reserved for resolved_url / canonical_url / redirect trail / adapters later
            $table->json('metadata_json')->nullable();
            $table->timestamps();

            $table->unique('normalized_url_hash', 'link_resources_normalized_url_hash_unique');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('link_resources');
    }
};
