<?php

declare(strict_types=1);

// Phase 7 — Agent Packs / Skill Studio (omi_seo_ai).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('seo_agent_packs')) {
            $schema->create('seo_agent_packs', function (Blueprint $table): void {
                $table->id();
                $table->string('hash_id', 64)->unique();
                $table->string('key', 128)->unique();
                $table->string('name', 255);
                $table->string('version', 32);
                $table->string('schema_version', 16)->default('1');
                $table->string('type', 32)->index(); // builtin|extension|custom|imported
                $table->string('source', 64)->default('studio');
                $table->string('trust', 32)->default('admin_created')->index();
                $table->string('status', 32)->default('discovered')->index();
                $table->string('health', 32)->default('unknown');
                $table->string('compatibility', 32)->default('unknown');
                $table->text('description')->nullable();
                $table->string('provider', 128)->nullable();
                $table->string('manifest_hash', 64)->nullable()->index();
                $table->unsignedBigInteger('active_revision_id')->nullable()->index();
                $table->json('metadata_json')->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamp('enabled_at')->nullable();
                $table->timestamp('disabled_at')->nullable();
                $table->timestamps();
                $table->softDeletes();

                $table->index(['status', 'type'], 'seo_agent_packs_status_type_index');
            });
        }

        if (! $schema->hasTable('seo_agent_pack_revisions')) {
            $schema->create('seo_agent_pack_revisions', function (Blueprint $table): void {
                $table->id();
                $table->string('hash_id', 64)->unique();
                $table->unsignedBigInteger('pack_id')->index();
                $table->string('version', 32);
                $table->unsignedInteger('revision_no')->default(1);
                $table->string('definition_hash', 64)->index();
                $table->string('status', 32)->default('draft')->index(); // draft|validated|active|failed|quarantined|superseded
                $table->json('manifest_json');
                $table->json('compiled_json')->nullable();
                $table->json('validation_report')->nullable();
                $table->json('gate_report')->nullable();
                $table->string('gate_status', 32)->nullable();
                $table->unsignedBigInteger('created_by')->nullable();
                $table->unsignedBigInteger('activated_by')->nullable();
                $table->timestamp('activated_at')->nullable();
                $table->timestamps();

                $table->unique(['pack_id', 'version', 'revision_no'], 'seo_agent_pack_rev_unique');
            });
        }

        if (! $schema->hasTable('seo_agent_pack_skills')) {
            $schema->create('seo_agent_pack_skills', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('pack_id')->index();
                $table->unsignedBigInteger('revision_id')->index();
                $table->string('skill_key', 160);
                $table->string('slash_command', 64);
                $table->string('capability', 160)->index();
                $table->json('definition_json');
                $table->timestamps();

                $table->unique(['revision_id', 'skill_key'], 'seo_agent_pack_skills_rev_key_unique');
                $table->unique(['revision_id', 'slash_command'], 'seo_agent_pack_skills_rev_cmd_unique');
            });
        }

        if (! $schema->hasTable('seo_agent_pack_templates')) {
            $schema->create('seo_agent_pack_templates', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('pack_id')->index();
                $table->unsignedBigInteger('revision_id')->index();
                $table->string('template_key', 160);
                $table->json('definition_json');
                $table->timestamps();

                $table->unique(['revision_id', 'template_key'], 'seo_agent_pack_tpl_rev_key_unique');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        foreach ([
            'seo_agent_pack_templates',
            'seo_agent_pack_skills',
            'seo_agent_pack_revisions',
            'seo_agent_packs',
        ] as $table) {
            if ($schema->hasTable($table)) {
                $schema->drop($table);
            }
        }
    }
};
