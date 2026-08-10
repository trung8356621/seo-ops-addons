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

        if (! $schema->hasTable('seo_site_sync_runs')) {
            $schema->create('seo_site_sync_runs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('public_ref', 64)->unique();
                $table->string('mode', 16)->default('delta')->index();
                $table->string('status', 32)->default('pending')->index();
                $table->string('current_step', 64)->nullable()->index();
                $table->string('cursor', 255)->nullable();
                $table->string('run_token', 64)->nullable()->index();
                $table->boolean('resumable')->default(true);
                $table->unsignedBigInteger('triggered_by')->nullable()->index();
                $table->string('trigger_source', 32)->default('ui')->index();
                $table->json('counters')->nullable();
                $table->json('warnings')->nullable();
                $table->json('meta')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->index(['site_id', 'status']);
            });
        }

        if (! $schema->hasTable('seo_site_sync_run_steps')) {
            $schema->create('seo_site_sync_run_steps', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('run_id')->index();
                $table->string('step_key', 64)->index();
                $table->unsignedSmallInteger('step_order')->default(0);
                $table->string('status', 32)->default('pending')->index();
                $table->json('metrics')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();
                $table->unique(['run_id', 'step_key']);
            });
        }

        if (! $schema->hasTable('seo_site_sync_batches')) {
            $schema->create('seo_site_sync_batches', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id')->index();
                $table->unsignedBigInteger('run_id')->nullable()->index();
                $table->string('checksum', 64)->index();
                $table->string('mode', 16)->default('delta');
                $table->string('cursor', 255)->nullable();
                $table->boolean('has_more')->default(false);
                $table->longText('payload_json');
                $table->timestamp('applied_at')->nullable()->index();
                $table->timestamps();
                $table->unique(['site_id', 'checksum']);
            });
        }

        if (! $schema->hasTable('seo_site_capabilities')) {
            $schema->create('seo_site_capabilities', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id')->unique();
                $table->string('schema_version', 32)->default('site_sync.v1');
                $table->string('bridge_version', 32)->nullable();
                $table->string('site_url', 512)->nullable();
                $table->json('manifest');
                $table->timestamp('detected_at')->nullable();
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('seo_site_link_catalog')) {
            $schema->create('seo_site_link_catalog', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id')->index();
                $table->unsignedBigInteger('wordpress_id')->nullable()->index();
                $table->string('url', 2048);
                $table->string('url_hash', 64)->index();
                $table->string('canonical', 2048)->nullable();
                $table->string('slug', 255)->nullable();
                $table->string('title', 512)->nullable();
                $table->string('status', 32)->default('publish')->index();
                $table->string('type', 64)->default('article')->index();
                $table->string('content_hash', 64)->nullable()->index();
                $table->string('source', 32)->default('wordpress')->index();
                $table->timestamp('updated_at_wp')->nullable();
                $table->json('meta')->nullable();
                $table->timestamps();
                $table->unique(['site_id', 'url_hash', 'source']);
                $table->index(['site_id', 'wordpress_id', 'source']);
            });
        }

        if (! $schema->hasTable('seo_site_manual_links')) {
            $schema->create('seo_site_manual_links', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('keyword', 255)->nullable();
                $table->string('url', 2048);
                $table->string('url_hash', 64)->index();
                $table->boolean('is_locked')->default(true);
                $table->timestamps();
                $table->unique(['site_id', 'url_hash']);
            });
        }

        if (! $schema->hasTable('seo_site_link_exclusions')) {
            $schema->create('seo_site_link_exclusions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('url', 2048)->nullable();
                $table->string('url_hash', 64)->nullable()->index();
                $table->unsignedBigInteger('wordpress_id')->nullable()->index();
                $table->string('reason', 255)->nullable();
                $table->timestamps();
                $table->index(['site_id', 'wordpress_id']);
            });
        }

        if (! $schema->hasTable('seo_article_score_sources')) {
            $schema->create('seo_article_score_sources', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id')->index();
                $table->unsignedBigInteger('article_id')->nullable()->index();
                $table->unsignedBigInteger('wordpress_id')->nullable()->index();
                $table->string('source', 64)->index();
                $table->decimal('score', 8, 2)->nullable();
                $table->json('raw')->nullable();
                $table->timestamps();
                $table->unique(['site_id', 'wordpress_id', 'source']);
            });
        }

        if ($schema->hasTable('keywords') && ! $schema->hasColumn('keywords', 'source')) {
            $schema->table('keywords', function (Blueprint $table): void {
                $table->string('source', 32)->nullable()->after('type')->index();
                $table->boolean('source_locked')->default(false)->after('source');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('keywords') && $schema->hasColumn('keywords', 'source')) {
            $schema->table('keywords', function (Blueprint $table): void {
                $table->dropColumn(['source', 'source_locked']);
            });
        }

        $schema->dropIfExists('seo_article_score_sources');
        $schema->dropIfExists('seo_site_link_exclusions');
        $schema->dropIfExists('seo_site_manual_links');
        $schema->dropIfExists('seo_site_link_catalog');
        $schema->dropIfExists('seo_site_capabilities');
        $schema->dropIfExists('seo_site_sync_batches');
        $schema->dropIfExists('seo_site_sync_run_steps');
        $schema->dropIfExists('seo_site_sync_runs');
    }
};
