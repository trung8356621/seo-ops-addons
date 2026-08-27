<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Drop legacy Keyword Rule Group tables.
 * Semantic model is now: Domain → Keyword → Canonical Cluster → DNA.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('seo_keyword_rule_group_members');
        $schema->dropIfExists('seo_keyword_rule_group_rules');
        $schema->dropIfExists('seo_keyword_rule_groups');
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seo_keyword_rule_groups')) {
            $schema->create('seo_keyword_rule_groups', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('site_id')->nullable()->index();
                $table->string('group_key', 64);
                $table->string('label', 120);
                $table->string('group_type', 16)->default('system')->index();
                $table->boolean('is_active')->default(true)->index();
                $table->unsignedSmallInteger('sort_order')->default(0);
                $table->timestamps();
                $table->unique(['site_id', 'group_key'], 'seo_kw_rule_groups_site_key');
            });
        }

        if (! $schema->hasTable('seo_keyword_rule_group_rules')) {
            $schema->create('seo_keyword_rule_group_rules', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('group_id')->index();
                $table->string('match_type', 16)->default('contains');
                $table->string('phrase', 190);
                $table->string('folded_phrase', 190)->index();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! $schema->hasTable('seo_keyword_rule_group_members')) {
            $schema->create('seo_keyword_rule_group_members', function (Blueprint $table): void {
                $table->unsignedBigInteger('keyword_id');
                $table->unsignedBigInteger('group_id');
                $table->primary(['keyword_id', 'group_id']);
                $table->index('group_id');
            });
        }
    }
};
