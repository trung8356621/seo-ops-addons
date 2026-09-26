<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * Retire disposable SEO Social Profile + article social-link tables.
 * Historical create migrations are kept:
 * - 2026_08_28_100000_create_seo_social_profiles_table
 * - 2026_09_01_140000_create_seo_article_social_links_table
 *
 * Social work/reporting now lives in Seeding (seeding_social_accounts / Website Share reports).
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        $schema->dropIfExists('seo_article_social_links');
        $schema->dropIfExists('seo_social_profiles');
    }

    public function down(): void
    {
        // Intentionally empty — legacy SEO social storage is retired.
    }
};
