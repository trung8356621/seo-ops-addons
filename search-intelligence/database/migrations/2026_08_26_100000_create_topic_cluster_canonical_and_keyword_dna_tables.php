<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $schema = Schema::connection('omi_seo_ai');

        if (! $schema->hasTable('seo_topic_cluster_meta')) {
            $schema->create('seo_topic_cluster_meta', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('cluster_key', 120);
                $table->string('canonical_phrase', 255);
                $table->string('normalized_canonical', 255);
                $table->string('confidence', 20)->default('high');
                $table->boolean('needs_review')->default(false);
                $table->timestamps();

                $table->unique(['site_id', 'cluster_key'], 'seo_topic_cluster_meta_site_key_uq');
                $table->index(['site_id', 'normalized_canonical'], 'seo_topic_cluster_meta_site_norm_idx');
            });
        }

        if (! $schema->hasTable('seo_topic_cluster_aliases')) {
            $schema->create('seo_topic_cluster_aliases', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('cluster_key', 120);
                $table->string('alias_phrase', 255);
                $table->string('normalized_alias', 255);
                $table->timestamps();

                $table->unique(['site_id', 'normalized_alias'], 'seo_topic_cluster_aliases_site_norm_uq');
                $table->index(['site_id', 'cluster_key'], 'seo_topic_cluster_aliases_site_key_idx');
            });
        }

        if (! $schema->hasTable('seo_keyword_dna')) {
            $schema->create('seo_keyword_dna', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id')->index();
                $table->unsignedBigInteger('keyword_id');
                $table->string('cluster_key', 120);
                $table->string('value', 120);
                $table->string('normalized_value', 120);
                $table->string('facet_type', 32)->nullable();
                $table->string('confidence', 20)->nullable();
                $table->string('source', 32)->default('deterministic');
                $table->timestamps();

                $table->unique(['keyword_id', 'normalized_value'], 'seo_keyword_dna_kw_norm_uq');
                $table->index(['site_id', 'cluster_key', 'normalized_value'], 'seo_keyword_dna_site_cluster_norm_idx');
                $table->index(['cluster_key', 'normalized_value'], 'seo_keyword_dna_cluster_norm_idx');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection('omi_seo_ai');
        $schema->dropIfExists('seo_keyword_dna');
        $schema->dropIfExists('seo_topic_cluster_aliases');
        $schema->dropIfExists('seo_topic_cluster_meta');
    }
};
