<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A keyword may belong to one group per language scope.
 * Existing rows stay on the empty legacy scope. No membership is moved or deleted.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_keyword_group_keywords')) {
            return;
        }

        if (! $schema->hasColumn('seo_keyword_group_keywords', 'language')) {
            $schema->table('seo_keyword_group_keywords', function (Blueprint $table): void {
                $table->string('language', 32)->default('');
            });
        }

        $names = array_column($schema->getIndexes('seo_keyword_group_keywords'), 'name');
        if (in_array('seo_kw_group_keywords_site_kw_lang_uq', $names, true)) {
            return;
        }
        if (in_array('seo_kw_group_keywords_site_kw_uq', $names, true)) {
            $schema->table('seo_keyword_group_keywords', function (Blueprint $table): void {
                $table->dropUnique('seo_kw_group_keywords_site_kw_uq');
            });
        }
        $schema->table('seo_keyword_group_keywords', function (Blueprint $table): void {
            $table->unique(['site_id', 'keyword_id', 'language'], 'seo_kw_group_keywords_site_kw_lang_uq');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_keyword_group_keywords')) {
            return;
        }

        $names = array_column($schema->getIndexes('seo_keyword_group_keywords'), 'name');
        if (in_array('seo_kw_group_keywords_site_kw_lang_uq', $names, true)) {
            $schema->table('seo_keyword_group_keywords', function (Blueprint $table): void {
                $table->dropUnique('seo_kw_group_keywords_site_kw_lang_uq');
            });
        }
        if (! in_array('seo_kw_group_keywords_site_kw_uq', $names, true)) {
            $schema->table('seo_keyword_group_keywords', function (Blueprint $table): void {
                $table->unique(['site_id', 'keyword_id'], 'seo_kw_group_keywords_site_kw_uq');
            });
        }
        if ($schema->hasColumn('seo_keyword_group_keywords', 'language')) {
            $schema->table('seo_keyword_group_keywords', function (Blueprint $table): void {
                $table->dropColumn('language');
            });
        }
    }
};
