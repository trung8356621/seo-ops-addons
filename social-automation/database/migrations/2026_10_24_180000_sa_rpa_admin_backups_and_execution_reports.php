<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_social_automation';

    public function up(): void
    {
        Schema::connection($this->connection)->create('sa_rpa_admin_backups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('installation_id', 128);
            $table->unsignedBigInteger('draft_revision');
            $table->string('storage_disk', 64)->default('local');
            $table->string('storage_path', 512);
            $table->string('checksum_sha256', 64);
            $table->unsignedBigInteger('size_bytes');
            $table->json('summary');
            $table->timestamp('backed_up_at');
            $table->timestamps();
            $table->unique(['user_id', 'installation_id'], 'sa_rpa_admin_backup_owner_unique');
        });

        Schema::connection($this->connection)->create('sa_rpa_execution_reports', function (Blueprint $table): void {
            $table->id();
            $table->string('installation_id', 128);
            $table->string('script_id', 128);
            $table->string('script_version', 64);
            $table->string('step_id', 128)->nullable();
            $table->unsignedInteger('step_version')->nullable();
            $table->string('run_id', 128);
            $table->timestamp('occurred_at');
            $table->string('status', 32);
            $table->string('error_category', 64)->nullable();
            $table->json('details')->nullable();
            $table->timestamps();
            $table->unique(['installation_id', 'run_id', 'step_id', 'step_version'], 'sa_rpa_report_dedupe');
            $table->index(['id', 'occurred_at']);
            $table->index(['script_id', 'script_version']);
        });
        Schema::connection($this->connection)->create('sa_script_release_assets', function (Blueprint $table): void {
            $table->id(); $table->unsignedBigInteger('release_id'); $table->string('asset_path', 512);
            $table->string('checksum_sha256', 64); $table->string('mime', 128); $table->string('storage_path', 512); $table->unsignedBigInteger('size_bytes'); $table->timestamps();
            $table->unique(['release_id', 'asset_path'], 'sa_script_release_asset_unique');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('sa_script_release_assets');
        Schema::connection($this->connection)->dropIfExists('sa_rpa_execution_reports');
        Schema::connection($this->connection)->dropIfExists('sa_rpa_admin_backups');
    }
};
