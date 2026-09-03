<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop Keyword Intelligence cannibalization issues table.
 * Feature retired: multi-article same keyword is accepted in current workflow.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_keyword_cannibalization_issues');
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('seo_keyword_cannibalization_issues')) {
            return;
        }

        $schema->create('seo_keyword_cannibalization_issues', function (Blueprint $table): void {
            $table->id();
            $table->string('public_ref', 64)->unique();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->unsignedBigInteger('tenant_id')->nullable()->index();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('issue_type', 40)->index();
            $table->string('risk_level', 16)->index();
            $table->string('status', 16)->default('open')->index();
            $table->json('keyword_refs')->nullable();
            $table->json('cluster_refs')->nullable();
            $table->json('article_refs')->nullable();
            $table->json('reason_codes')->nullable();
            $table->text('summary')->nullable();
            $table->string('recommended_action', 64)->nullable();
            $table->decimal('confidence', 5, 2)->nullable();
            $table->string('source', 16)->default('rule');
            $table->string('fingerprint', 191)->index();
            $table->timestamp('detected_at')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamp('resolved_at')->nullable();
            $table->unsignedBigInteger('resolved_by')->nullable();
            $table->string('resolution_code', 64)->nullable();
            $table->timestamps();

            $table->unique(['workspace_id', 'fingerprint'], 'seo_kw_cannibalization_workspace_fingerprint_unique');
        });
    }
};
