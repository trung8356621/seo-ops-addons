<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Persist DNA before|after placement on Keywords SSOT (seo_keyword_dna).
 */
return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        if (! $schema->hasTable('seo_keyword_dna')) {
            return;
        }
        if ($schema->hasColumn('seo_keyword_dna', 'placement')) {
            return;
        }

        $schema->table('seo_keyword_dna', function (Blueprint $table): void {
            $table->string('placement', 16)->default('after')->after('source');
            $table->index(['site_id', 'cluster_key', 'placement'], 'seo_keyword_dna_site_cluster_placement_idx');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        if (! $schema->hasTable('seo_keyword_dna')) {
            return;
        }
        if (! $schema->hasColumn('seo_keyword_dna', 'placement')) {
            return;
        }

        $schema->table('seo_keyword_dna', function (Blueprint $table): void {
            $table->dropIndex('seo_keyword_dna_site_cluster_placement_idx');
            $table->dropColumn('placement');
        });
    }
};
