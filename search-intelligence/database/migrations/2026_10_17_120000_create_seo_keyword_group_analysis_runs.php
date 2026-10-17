<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Current async keyword-group analysis run for one site.
 * Does not store group memberships. MySQL groups stay until finalize commits.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if ($schema->hasTable('seo_keyword_group_analysis_runs')) {
            return;
        }

        $schema->create('seo_keyword_group_analysis_runs', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->unique('seo_kw_group_analysis_run_site_uq');
            $table->string('run_id', 64);
            $table->string('language', 32)->nullable();
            $table->unsignedInteger('dataset_revision');
            $table->string('dataset_hash', 64);
            $table->string('fingerprint', 64);
            $table->string('algorithm', 64);
            $table->string('phase', 32);
            $table->unsignedInteger('group_count')->default(0);
            $table->unsignedInteger('member_count')->default(0);
            $table->string('last_error', 255)->nullable();
            $table->timestamp('finalized_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_keyword_group_analysis_runs');
    }
};
