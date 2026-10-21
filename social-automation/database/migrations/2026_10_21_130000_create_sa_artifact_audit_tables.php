<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase E — Artifacts + audit trail.
 */
return new class extends Migration
{
    protected $connection = 'omi_social_automation';

    public function up(): void
    {
        Schema::connection($this->connection)->create('sa_artifacts', function (Blueprint $table): void {
            $table->id();
            $table->uuid('artifact_uuid')->unique();
            $table->foreignId('job_id')->constrained('sa_jobs')->cascadeOnDelete();
            $table->foreignId('attempt_id')->nullable()->constrained('sa_job_attempts')->nullOnDelete();
            $table->string('kind', 32); // screenshot|video|log|other
            $table->string('mime', 128);
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('storage_disk', 64)->default('local');
            $table->string('storage_path', 512);
            $table->string('checksum_sha256', 64)->nullable();
            $table->json('metadata')->nullable(); // never secrets
            $table->timestamp('retain_until')->nullable()->index();
            $table->timestamps();

            $table->index(['job_id', 'kind']);
        });

        Schema::connection($this->connection)->create('sa_audit_logs', function (Blueprint $table): void {
            $table->id();
            $table->string('actor_type', 32); // admin|runner|system
            $table->string('actor_id', 64)->nullable();
            $table->string('action', 64);
            $table->string('subject_type', 64)->nullable();
            $table->string('subject_id', 64)->nullable();
            $table->json('context')->nullable();
            $table->timestamp('occurred_at');
            $table->timestamps();

            $table->index(['subject_type', 'subject_id']);
            $table->index(['action', 'occurred_at']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('sa_audit_logs');
        Schema::connection($this->connection)->dropIfExists('sa_artifacts');
    }
};
