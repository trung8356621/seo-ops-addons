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
        if (! Schema::connection($this->connection)->hasTable('seo_content_project_idempotency_keys')) {
            Schema::connection($this->connection)->create('seo_content_project_idempotency_keys', function (Blueprint $table): void {
                $table->id();
                $table->string('tenant_key', 64)->index();
                $table->string('action', 96)->index();
                $table->string('idempotency_key', 128);
                $table->string('status', 32)->default('processing')->index(); // processing|succeeded|failed
                $table->json('result_payload')->nullable();
                $table->timestamp('expires_at')->index();
                $table->timestamps();

                $table->unique(['tenant_key', 'action', 'idempotency_key'], 'cp_idem_unique');
            });
        }

        if (! Schema::connection($this->connection)->hasTable('seo_content_project_business_audits')) {
            Schema::connection($this->connection)->create('seo_content_project_business_audits', function (Blueprint $table): void {
                $table->id();
                $table->string('actor_type', 32)->index();
                $table->unsignedBigInteger('actor_id')->nullable()->index();
                $table->string('action', 96)->index();
                $table->string('project_ref', 64)->nullable()->index();
                $table->string('item_ref', 64)->nullable()->index();
                $table->string('result', 32)->index(); // success|failed|processing
                $table->string('result_code', 96)->nullable();
                $table->json('metadata')->nullable();
                $table->timestamp('occurred_at')->index();
                $table->timestamps();
            });
        }

        if (! Schema::connection($this->connection)->hasTable('seo_content_project_publish_attempts')) {
            Schema::connection($this->connection)->create('seo_content_project_publish_attempts', function (Blueprint $table): void {
                $table->id();
                $table->string('attempt_ref', 64)->unique();
                $table->unsignedBigInteger('project_id')->index();
                $table->unsignedBigInteger('task_id')->index();
                $table->unsignedBigInteger('article_id')->index();
                $table->string('external_reference', 128)->nullable()->index();
                $table->unsignedBigInteger('wp_post_id')->nullable()->index();
                $table->string('status', 32)->default('requested')->index();
                $table->string('idempotency_key', 128)->nullable()->index();
                $table->text('last_error')->nullable();
                $table->timestamp('requested_at')->nullable();
                $table->timestamp('completed_at')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_content_project_publish_attempts');
        Schema::connection($this->connection)->dropIfExists('seo_content_project_business_audits');
        Schema::connection($this->connection)->dropIfExists('seo_content_project_idempotency_keys');
    }
};
