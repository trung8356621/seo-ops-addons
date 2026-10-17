<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One analysis-run row per site and language.
 * Existing rows keep their stored language. No group or membership rewrite.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_keyword_group_analysis_runs')) {
            return;
        }

        $names = array_column($schema->getIndexes('seo_keyword_group_analysis_runs'), 'name');
        if (in_array('seo_kw_group_analysis_run_site_lang_uq', $names, true)) {
            return;
        }
        if (in_array('seo_kw_group_analysis_run_site_uq', $names, true)) {
            $schema->table('seo_keyword_group_analysis_runs', function (Blueprint $table): void {
                $table->dropUnique('seo_kw_group_analysis_run_site_uq');
            });
        }
        $schema->table('seo_keyword_group_analysis_runs', function (Blueprint $table): void {
            $table->unique(['site_id', 'language'], 'seo_kw_group_analysis_run_site_lang_uq');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_keyword_group_analysis_runs')) {
            return;
        }

        $names = array_column($schema->getIndexes('seo_keyword_group_analysis_runs'), 'name');
        if (! in_array('seo_kw_group_analysis_run_site_lang_uq', $names, true)) {
            return;
        }
        $schema->table('seo_keyword_group_analysis_runs', function (Blueprint $table): void {
            $table->dropUnique('seo_kw_group_analysis_run_site_lang_uq');
            $table->unique('site_id', 'seo_kw_group_analysis_run_site_uq');
        });
    }
};
