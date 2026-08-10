<?php

declare(strict_types=1);

// Phase 3 — agent planning runs + conversation summary columns (omi_seo_ai).

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('seo_agent_conversations')) {
            $schema->table('seo_agent_conversations', function (Blueprint $table) use ($schema): void {
                if (! $schema->hasColumn('seo_agent_conversations', 'summary')) {
                    $table->text('summary')->nullable()->after('context_summary');
                }
                if (! $schema->hasColumn('seo_agent_conversations', 'summary_version')) {
                    $table->unsignedInteger('summary_version')->default(0)->after('summary');
                }
                if (! $schema->hasColumn('seo_agent_conversations', 'summary_until_message_id')) {
                    $table->unsignedBigInteger('summary_until_message_id')->nullable()->after('summary_version');
                }
                if (! $schema->hasColumn('seo_agent_conversations', 'summary_updated_at')) {
                    $table->timestamp('summary_updated_at')->nullable()->after('summary_until_message_id');
                }
            });
        }

        if (! $schema->hasTable('seo_agent_planning_runs')) {
            $schema->create('seo_agent_planning_runs', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('conversation_id')->index();
                $table->unsignedBigInteger('message_id')->nullable()->index();
                $table->string('planning_request_id', 64)->unique();
                $table->string('status', 32)->index();
                $table->string('response_type', 32)->nullable()->index();
                $table->string('provider', 64)->nullable();
                $table->string('model', 128)->nullable();
                $table->string('routing_reason', 128)->nullable();
                $table->unsignedInteger('input_token_estimate')->nullable();
                $table->unsignedInteger('output_token_estimate')->nullable();
                $table->decimal('confidence', 5, 4)->nullable();
                $table->decimal('adjusted_confidence', 5, 4)->nullable();
                $table->string('prompt_fingerprint', 64)->nullable();
                $table->json('context_manifest')->nullable();
                $table->json('structured_response')->nullable();
                $table->json('validation_errors')->nullable();
                $table->json('repair_actions')->nullable();
                $table->unsignedInteger('latency_ms')->nullable();
                $table->string('error_category', 64)->nullable();
                $table->timestamp('started_at')->nullable();
                $table->timestamp('finished_at')->nullable();
                $table->timestamps();

                $table->index(['conversation_id', 'created_at'], 'seo_agent_planning_runs_conv_created_index');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasTable('seo_agent_planning_runs')) {
            $schema->drop('seo_agent_planning_runs');
        }

        if ($schema->hasTable('seo_agent_conversations')) {
            $schema->table('seo_agent_conversations', function (Blueprint $table) use ($schema): void {
                foreach (['summary_updated_at', 'summary_until_message_id', 'summary_version', 'summary'] as $col) {
                    if ($schema->hasColumn('seo_agent_conversations', $col)) {
                        $table->dropColumn($col);
                    }
                }
            });
        }
    }
};
