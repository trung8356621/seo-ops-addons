<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Script Remove tombstones (suppress re-import by stable script_id + source)
 * + per-release video_demo / thumbnail media ownership.
 */
return new class extends Migration
{
    protected $connection = 'omi_social_automation';

    public function up(): void
    {
        Schema::connection($this->connection)->create('sa_script_tombstones', function (Blueprint $table): void {
            $table->id();
            $table->string('script_id', 128);
            $table->string('source', 32); // rpa_admin | service_api | …
            $table->string('package_key', 128)->nullable(); // informational only — not identity
            $table->string('content_digest', 64)->nullable();
            $table->unsignedBigInteger('removed_by_user_id')->nullable();
            $table->timestamp('removed_at');
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();

            $table->unique(['script_id', 'source'], 'sa_script_tombstones_id_source_unique');
            $table->index(['source', 'restored_at']);
        });

        Schema::connection($this->connection)->create('sa_script_release_media', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('release_id');
            $table->string('kind', 32); // video_demo | thumbnail
            $table->string('storage_disk', 64)->default('local');
            $table->string('storage_path', 512);
            $table->string('mime', 128);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('checksum_sha256', 64)->nullable();
            $table->string('status', 32)->default('ready'); // ready | unavailable | pending
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique(['release_id', 'kind'], 'sa_script_release_media_release_kind_unique');
            $table->index('release_id');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('sa_script_release_media');
        Schema::connection($this->connection)->dropIfExists('sa_script_tombstones');
    }
};
