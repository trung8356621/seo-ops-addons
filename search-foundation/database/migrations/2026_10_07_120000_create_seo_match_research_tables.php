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
        Schema::connection($this->connection)->create('seo_match_research_custom_concepts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('resource_key', 191);
            $table->string('source_locale', 16);
            $table->string('name', 255);
            $table->text('description')->nullable();
            $table->json('positive_examples')->nullable();
            $table->json('negative_examples')->nullable();
            $table->boolean('enabled')->default(true);
            $table->timestamps();

            $table->unique(['site_id', 'resource_key'], 'match_research_custom_site_key_uq');
        });

        Schema::connection($this->connection)->create('seo_match_research_locale_overlays', function (Blueprint $table): void {
            $table->id();
            // 0 = global/system-or-industry overlay (DB-agnostic unique; avoid NULL unique quirks)
            $table->unsignedBigInteger('site_id')->default(0)->index();
            $table->string('resource_key', 191);
            $table->string('locale', 16);
            $table->json('payload');
            $table->timestamps();

            $table->unique(['site_id', 'resource_key', 'locale'], 'match_research_locale_site_key_locale_uq');
        });

        Schema::connection($this->connection)->create('seo_match_consumer_policies', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('policy_key', 191);
            $table->json('resource_keys');
            $table->timestamps();

            $table->unique(['site_id', 'policy_key'], 'match_research_consumer_policy_uq');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_match_consumer_policies');
        Schema::connection($this->connection)->dropIfExists('seo_match_research_locale_overlays');
        Schema::connection($this->connection)->dropIfExists('seo_match_research_custom_concepts');
    }
};
