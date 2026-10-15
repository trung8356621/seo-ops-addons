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

        if (! $schema->hasTable('seo_keyword_quality_tags')) {
            $schema->create('seo_keyword_quality_tags', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id');
                $table->unsignedBigInteger('keyword_id');
                $table->string('code', 32);
                $table->string('origin', 16);
                $table->timestamps();
                $table->unique(['site_id', 'keyword_id', 'code'], 'seo_kw_quality_tag_uq');
                $table->index(['site_id', 'code'], 'seo_kw_quality_tag_code_idx');
            });
        }

        if (! $schema->hasTable('seo_keyword_quality_suppressions')) {
            $schema->create('seo_keyword_quality_suppressions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id');
                $table->unsignedBigInteger('keyword_id');
                $table->string('code', 32);
                $table->timestamps();
                $table->unique(['site_id', 'keyword_id', 'code'], 'seo_kw_quality_suppress_uq');
            });
        }

        if (! $schema->hasTable('seo_keyword_quality_policies')) {
            $schema->create('seo_keyword_quality_policies', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id');
                $table->string('code', 32);
                $table->boolean('exclude_from_topic')->default(false);
                $table->timestamps();
                $table->unique(['site_id', 'code'], 'seo_kw_quality_policy_uq');
            });
        }

        if (! $schema->hasTable('seo_keyword_quality_seen')) {
            $schema->create('seo_keyword_quality_seen', function (Blueprint $table): void {
                $table->unsignedBigInteger('site_id');
                $table->unsignedBigInteger('keyword_id');
                $table->unsignedSmallInteger('classifier_version');
                $table->timestamps();
                $table->primary(['site_id', 'keyword_id'], 'seo_kw_quality_seen_pk');
            });
        }

        if (! $schema->hasTable('seo_keyword_quality_scans')) {
            $schema->create('seo_keyword_quality_scans', function (Blueprint $table): void {
                $table->unsignedBigInteger('site_id')->primary();
                $table->string('status', 16);
                $table->unsignedInteger('total')->default(0);
                $table->unsignedInteger('processed')->default(0);
                $table->string('last_error', 255)->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('seo_keyword_quality_scans');
        $schema->dropIfExists('seo_keyword_quality_seen');
        $schema->dropIfExists('seo_keyword_quality_policies');
        $schema->dropIfExists('seo_keyword_quality_suppressions');
        $schema->dropIfExists('seo_keyword_quality_tags');
    }
};
