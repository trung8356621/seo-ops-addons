<?php

declare(strict_types=1);

// Phase 2 — extend seo_agent_executions + agent execution plans (omi_seo_ai).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('seo_agent_executions')) {
            $schema->table('seo_agent_executions', function (Blueprint $table) use ($schema): void {
                if (! $schema->hasColumn('seo_agent_executions', 'parent_execution_id')) {
                    $table->unsignedBigInteger('parent_execution_id')->nullable()->index()->after('message_id');
                }
                if (! $schema->hasColumn('seo_agent_executions', 'plan_id')) {
                    $table->unsignedBigInteger('plan_id')->nullable()->index()->after('parent_execution_id');
                }
                if (! $schema->hasColumn('seo_agent_executions', 'step_index')) {
                    $table->unsignedSmallInteger('step_index')->nullable()->after('plan_id');
                }
                if (! $schema->hasColumn('seo_agent_executions', 'mode')) {
                    $table->string('mode', 32)->default('execute')->after('capability');
                }
                if (! $schema->hasColumn('seo_agent_executions', 'input_payload')) {
                    $table->json('input_payload')->nullable()->after('input_summary');
                }
                if (! $schema->hasColumn('seo_agent_executions', 'preview_payload')) {
                    $table->json('preview_payload')->nullable()->after('input_payload');
                }
                if (! $schema->hasColumn('seo_agent_executions', 'result_payload')) {
                    $table->json('result_payload')->nullable()->after('result_summary');
                }
                if (! $schema->hasColumn('seo_agent_executions', 'error_payload')) {
                    $table->json('error_payload')->nullable()->after('error_code');
                }
                if (! $schema->hasColumn('seo_agent_executions', 'confirmation_policy')) {
                    $table->string('confirmation_policy', 32)->nullable()->after('confirmation_ref');
                }
                if (! $schema->hasColumn('seo_agent_executions', 'confirmation_token_hash')) {
                    $table->string('confirmation_token_hash', 64)->nullable()->after('confirmation_policy');
                }
                if (! $schema->hasColumn('seo_agent_executions', 'confirmation_expires_at')) {
                    $table->timestamp('confirmation_expires_at')->nullable()->index()->after('confirmation_token_hash');
                }
                if (! $schema->hasColumn('seo_agent_executions', 'confirmed_at')) {
                    $table->timestamp('confirmed_at')->nullable()->after('confirmation_expires_at');
                }
                if (! $schema->hasColumn('seo_agent_executions', 'confirmed_by')) {
                    $table->unsignedBigInteger('confirmed_by')->nullable()->after('confirmed_at');
                }
                if (! $schema->hasColumn('seo_agent_executions', 'idempotency_key')) {
                    $table->string('idempotency_key', 128)->nullable()->unique()->after('operation_ref');
                }
                if (! $schema->hasColumn('seo_agent_executions', 'attempt')) {
                    $table->unsignedSmallInteger('attempt')->default(1)->after('idempotency_key');
                }
                if (! $schema->hasColumn('seo_agent_executions', 'cancelled_at')) {
                    $table->timestamp('cancelled_at')->nullable()->after('completed_at');
                }
            });

            if (! $this->hasIndex('seo_agent_executions', 'seo_agent_executions_conversation_created_index')) {
                $schema->table('seo_agent_executions', function (Blueprint $table): void {
                    $table->index(['conversation_id', 'created_at'], 'seo_agent_executions_conversation_created_index');
                });
            }
            if (! $this->hasIndex('seo_agent_executions', 'seo_agent_executions_plan_step_index')) {
                $schema->table('seo_agent_executions', function (Blueprint $table): void {
                    $table->index(['plan_id', 'step_index'], 'seo_agent_executions_plan_step_index');
                });
            }
        }

        if (! $schema->hasTable('seo_agent_execution_plans')) {
            $schema->create('seo_agent_execution_plans', function (Blueprint $table): void {
                $table->id();
                $table->string('public_ref', 64)->unique();
                $table->unsignedBigInteger('conversation_id')->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->unsignedBigInteger('created_by')->nullable()->index();
                $table->string('status', 32)->default('draft')->index();
                $table->unsignedSmallInteger('current_step_index')->default(0);
                $table->json('steps')->nullable();
                $table->json('bindings')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();
                $table->index(['conversation_id', 'created_at'], 'seo_agent_plans_conversation_created_index');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        $schema->dropIfExists('seo_agent_execution_plans');

        if (! $schema->hasTable('seo_agent_executions')) {
            return;
        }

        $schema->table('seo_agent_executions', function (Blueprint $table) use ($schema): void {
            foreach ([
                'parent_execution_id',
                'plan_id',
                'step_index',
                'mode',
                'input_payload',
                'preview_payload',
                'result_payload',
                'error_payload',
                'confirmation_policy',
                'confirmation_token_hash',
                'confirmation_expires_at',
                'confirmed_at',
                'confirmed_by',
                'idempotency_key',
                'attempt',
                'cancelled_at',
            ] as $column) {
                if ($schema->hasColumn('seo_agent_executions', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    private function hasIndex(string $table, string $indexName): bool
    {
        $indexes = Schema::connection($this->connection)->getIndexes($table);
        foreach ($indexes as $index) {
            if (($index['name'] ?? null) === $indexName) {
                return true;
            }
        }

        return false;
    }
};
