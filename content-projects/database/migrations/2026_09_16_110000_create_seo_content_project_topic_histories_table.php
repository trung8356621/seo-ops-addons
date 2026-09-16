<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Database\Migrations;

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Topic History: Topic + Target DNA (planned article count) written when a plan is created.
 * NO legacy backfill from projects/articles.
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        if ($schema->hasTable('seo_content_project_topic_histories')) {
            return;
        }

        $schema->create('seo_content_project_topic_histories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->date('planning_month')->index();
            $table->string('topic_ref', 191)->nullable();
            $table->string('topic_name', 500);
            $table->unsignedInteger('planned_article_count');
            $table->unsignedBigInteger('planner_run_id')->nullable()->index();
            $table->timestamps();

            $table->unique(
                ['planner_run_id', 'topic_ref'],
                'scp_topic_hist_run_topic_unique',
            );
            $table->index(
                ['site_id', 'planning_month'],
                'scp_topic_hist_site_month_idx',
            );
        });
    }

    public function down(): void
    {
        Schema::connection('omi_seo_ai')->dropIfExists('seo_content_project_topic_histories');
    }
};
