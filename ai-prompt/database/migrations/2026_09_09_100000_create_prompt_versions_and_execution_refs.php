<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('prompt_versions')) {
            $schema->create('prompt_versions', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('prompt_id')->index();
                $table->string('version_label', 32);
                $table->unsignedInteger('sequence')->default(1);
                $table->longText('markdown_content')->nullable();
                $table->string('hook_key')->nullable();
                $table->string('hook_version')->nullable();
                $table->json('hook_settings')->nullable();
                $table->json('settings')->nullable();
                $table->string('tools', 64)->nullable();
                $table->json('variables')->nullable();
                $table->char('content_fingerprint', 64)->index();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->timestamp('created_at')->nullable();

                $table->unique(['prompt_id', 'sequence'], 'prompt_versions_prompt_sequence_unique');
                $table->index(['prompt_id', 'version_label'], 'prompt_versions_prompt_label_idx');
            });
        }

        if ($schema->hasTable('prompts') && ! $schema->hasColumn('prompts', 'current_prompt_version_id')) {
            $schema->table('prompts', function (Blueprint $table): void {
                $table->unsignedBigInteger('current_prompt_version_id')->nullable()->after('id')->index();
            });
        }

        if ($schema->hasTable('prompt_results')) {
            $schema->table('prompt_results', function (Blueprint $table) use ($schema): void {
                if (! $schema->hasColumn('prompt_results', 'prompt_version_id')) {
                    $table->unsignedBigInteger('prompt_version_id')->nullable()->after('prompt_id')->index();
                }
                if (! $schema->hasColumn('prompt_results', 'canonical_prompt_key')) {
                    $table->string('canonical_prompt_key', 191)->nullable()->index();
                }
                if (! $schema->hasColumn('prompt_results', 'stage')) {
                    $table->string('stage', 191)->nullable();
                }
                if (! $schema->hasColumn('prompt_results', 'compiled_prompt_hash')) {
                    $table->char('compiled_prompt_hash', 64)->nullable();
                }
                if (! $schema->hasColumn('prompt_results', 'content_project_id')) {
                    $table->unsignedBigInteger('content_project_id')->nullable()->index();
                }
                if (! $schema->hasColumn('prompt_results', 'project_item_id')) {
                    $table->unsignedBigInteger('project_item_id')->nullable()->index();
                }
                if (! $schema->hasColumn('prompt_results', 'run_id')) {
                    $table->unsignedBigInteger('run_id')->nullable()->index();
                }
                if (! $schema->hasColumn('prompt_results', 'node_id')) {
                    $table->string('node_id', 120)->nullable();
                }
                if (! $schema->hasColumn('prompt_results', 'retry_attempt')) {
                    $table->unsignedInteger('retry_attempt')->nullable();
                }
                if (! $schema->hasColumn('prompt_results', 'correlation_id')) {
                    $table->string('correlation_id', 191)->nullable()->index();
                }
                if (! $schema->hasColumn('prompt_results', 'failure_category')) {
                    $table->string('failure_category', 64)->nullable()->index();
                }
                if (! $schema->hasColumn('prompt_results', 'failure_code')) {
                    $table->string('failure_code', 128)->nullable();
                }
            });
        }

        if (! $schema->hasTable('prompt_result_routing_attempts')) {
            $schema->create('prompt_result_routing_attempts', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('prompt_result_id')->index();
                $table->unsignedInteger('sequence')->default(1);
                $table->string('logical_model', 191)->nullable();
                $table->string('physical_route', 191)->nullable();
                $table->string('provider', 64)->nullable();
                $table->unsignedBigInteger('connection_id')->nullable()->index();
                $table->string('connection_name', 191)->nullable();
                $table->string('provider_model', 191)->nullable();
                $table->string('cost_class', 32)->nullable();
                $table->string('state', 32)->nullable();
                $table->boolean('attempted')->default(false);
                $table->string('skip_reason', 191)->nullable();
                $table->unsignedSmallInteger('http_status')->nullable();
                $table->string('failure_category', 64)->nullable();
                $table->string('failure_code', 128)->nullable();
                $table->string('failure_scope', 64)->nullable();
                $table->string('health_mutation', 64)->nullable();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->json('token_usage')->nullable();
                $table->json('raw')->nullable();
                $table->timestamps();

                $table->unique(['prompt_result_id', 'sequence'], 'pr_routing_attempts_result_seq_unique');
                if (Schema::connection($this->connection)->hasTable('prompt_results')) {
                    $table->foreign('prompt_result_id', 'pr_routing_attempts_result_fk')
                        ->references('id')
                        ->on('prompt_results')
                        ->cascadeOnDelete();
                }
            });
        }

        $this->backfillInitialPromptVersions();
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('prompt_result_routing_attempts');

        if ($schema->hasTable('prompt_results')) {
            $schema->table('prompt_results', function (Blueprint $table) use ($schema): void {
                foreach ([
                    'prompt_version_id',
                    'canonical_prompt_key',
                    'stage',
                    'compiled_prompt_hash',
                    'content_project_id',
                    'project_item_id',
                    'run_id',
                    'node_id',
                    'retry_attempt',
                    'correlation_id',
                    'failure_category',
                    'failure_code',
                ] as $column) {
                    if ($schema->hasColumn('prompt_results', $column)) {
                        $table->dropColumn($column);
                    }
                }
            });
        }

        if ($schema->hasTable('prompts') && $schema->hasColumn('prompts', 'current_prompt_version_id')) {
            $schema->table('prompts', function (Blueprint $table): void {
                $table->dropColumn('current_prompt_version_id');
            });
        }

        $schema->dropIfExists('prompt_versions');
    }

    private function backfillInitialPromptVersions(): void
    {
        $db = DB::connection($this->connection);
        if (! Schema::connection($this->connection)->hasTable('prompts')
            || ! Schema::connection($this->connection)->hasTable('prompt_versions')) {
            return;
        }

        $prompts = $db->table('prompts')->whereNull('deleted_at')->orderBy('id')->get();
        foreach ($prompts as $prompt) {
            $existing = $db->table('prompt_versions')->where('prompt_id', $prompt->id)->count();
            if ($existing > 0) {
                continue;
            }

            $updatedAt = $prompt->updated_at ?? $prompt->created_at ?? now();
            $at = \Illuminate\Support\Carbon::parse((string) $updatedAt);
            $label = sprintf('%d.%d.%02d', (int) $at->format('j'), (int) $at->format('n'), (int) $at->format('y'));

            $settings = is_string($prompt->settings ?? null)
                ? json_decode((string) $prompt->settings, true)
                : (is_array($prompt->settings ?? null) ? $prompt->settings : []);
            $hookSettings = is_string($prompt->hook_settings ?? null)
                ? json_decode((string) $prompt->hook_settings, true)
                : (is_array($prompt->hook_settings ?? null) ? $prompt->hook_settings : []);
            $variables = is_string($prompt->variables ?? null)
                ? json_decode((string) $prompt->variables, true)
                : (is_array($prompt->variables ?? null) ? $prompt->variables : []);

            $settingsArr = is_array($settings) ? $settings : [];
            $fingerprint = \Omnichannel\Addons\AiPrompt\Services\PromptVersionService::hashFingerprintPayload(
                \Omnichannel\Addons\AiPrompt\Services\PromptVersionService::fingerprintPayload(
                    (string) ($prompt->markdown_content ?? ''),
                    (string) ($prompt->hook_key ?? ''),
                    (string) ($prompt->hook_version ?? ''),
                    is_array($hookSettings) ? $hookSettings : [],
                    (string) ($prompt->tools ?? 'default'),
                    is_array($settingsArr['post_processing'] ?? null) ? $settingsArr['post_processing'] : [],
                ),
            );

            $versionId = $db->table('prompt_versions')->insertGetId([
                'prompt_id' => $prompt->id,
                'version_label' => $label,
                'sequence' => 1,
                'markdown_content' => $prompt->markdown_content,
                'hook_key' => $prompt->hook_key,
                'hook_version' => $prompt->hook_version,
                'hook_settings' => is_array($hookSettings) ? json_encode($hookSettings) : $prompt->hook_settings,
                'settings' => is_array($settings) ? json_encode($settings) : $prompt->settings,
                'tools' => $prompt->tools ?? 'default',
                'variables' => is_array($variables) ? json_encode($variables) : $prompt->variables,
                'content_fingerprint' => $fingerprint,
                'created_by' => $prompt->user_id ?? null,
                'created_at' => $at,
            ]);

            $db->table('prompts')->where('id', $prompt->id)->update([
                'current_prompt_version_id' => $versionId,
            ]);
        }
    }
};
