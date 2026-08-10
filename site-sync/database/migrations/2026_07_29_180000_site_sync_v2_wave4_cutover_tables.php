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

        if (! $schema->hasTable('seo_site_sync_cutover_states')) {
            $schema->create('seo_site_sync_cutover_states', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id')->unique();
                $table->string('mode', 32)->default('legacy_active')->index();
                $table->string('previous_mode', 32)->nullable();
                $table->unsignedBigInteger('checkpoint_id')->nullable()->index();
                $table->timestamp('shadow_started_at')->nullable();
                $table->timestamp('activated_at')->nullable();
                $table->timestamp('rolled_back_at')->nullable();
                $table->json('metrics')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->index(['site_id', 'mode']);
            });
        }

        if (! $schema->hasTable('seo_site_sync_checkpoints')) {
            $schema->create('seo_site_sync_checkpoints', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('purpose', 64)->index();
                $table->string('from_mode', 32)->nullable();
                $table->string('to_mode', 32)->nullable();
                $table->string('actor_type', 32)->nullable();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->string('reason', 500)->nullable();
                $table->json('snapshot')->nullable();
                $table->timestamps();
                $table->index(['site_id', 'created_at']);
            });
        }

        if (! $schema->hasTable('seo_site_sync_comparison_runs')) {
            $schema->create('seo_site_sync_comparison_runs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('public_ref', 40)->unique();
                $table->string('status', 32)->default('pending')->index();
                $table->string('scope', 32)->default('summary');
                $table->unsignedInteger('blocking_count')->default(0);
                $table->unsignedInteger('needs_review_count')->default(0);
                $table->unsignedInteger('expected_count')->default(0);
                $table->json('summary')->nullable();
                $table->string('export_path', 500)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->index(['site_id', 'status']);
            });
        }

        if (! $schema->hasTable('seo_site_sync_comparison_diffs')) {
            $schema->create('seo_site_sync_comparison_diffs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('run_id')->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('group_key', 32)->index();
                $table->string('entity_key', 191)->nullable()->index();
                $table->string('classification', 64)->index();
                $table->string('reason_code', 64)->index();
                $table->string('message', 500)->nullable();
                $table->json('legacy_value')->nullable();
                $table->json('v2_value')->nullable();
                $table->timestamps();
                $table->index(['site_id', 'classification']);
            });
        }

        if (! $schema->hasTable('seo_site_sync_provider_timeline')) {
            $schema->create('seo_site_sync_provider_timeline', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('provider', 64)->index();
                $table->string('provider_version', 64)->nullable();
                $table->string('edition', 64)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('ended_at')->nullable();
                $table->string('reason', 191)->nullable();
                $table->json('manifest_snippet')->nullable();
                $table->timestamps();
                $table->index(['site_id', 'started_at']);
            });
        }

        if (! $schema->hasTable('seo_site_sync_repair_plans')) {
            $schema->create('seo_site_sync_repair_plans', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('public_ref', 40)->unique();
                $table->string('status', 32)->default('preview')->index();
                $table->boolean('dry_run')->default(true);
                $table->json('items')->nullable();
                $table->json('result')->nullable();
                $table->unsignedBigInteger('actor_id')->nullable();
                $table->timestamps();
                $table->index(['site_id', 'status']);
            });
        }

        if (! $schema->hasTable('seo_site_sync_heartbeats')) {
            $schema->create('seo_site_sync_heartbeats', function (Blueprint $table): void {
                $table->id();
                $table->string('channel', 64)->unique();
                $table->timestamp('last_seen_at')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        foreach ([
            'seo_site_sync_heartbeats',
            'seo_site_sync_repair_plans',
            'seo_site_sync_provider_timeline',
            'seo_site_sync_comparison_diffs',
            'seo_site_sync_comparison_runs',
            'seo_site_sync_checkpoints',
            'seo_site_sync_cutover_states',
        ] as $table) {
            $schema->dropIfExists($table);
        }
    }
};
