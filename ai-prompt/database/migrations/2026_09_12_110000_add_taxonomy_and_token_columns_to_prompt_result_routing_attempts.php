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
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('prompt_result_routing_attempts')) {
            return;
        }

        $schema->table('prompt_result_routing_attempts', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('prompt_result_routing_attempts', 'addon')) {
                $table->string('addon', 32)->nullable()->index();
            }
            if (! $schema->hasColumn('prompt_result_routing_attempts', 'module')) {
                $table->string('module', 64)->nullable()->index();
            }
            if (! $schema->hasColumn('prompt_result_routing_attempts', 'action')) {
                $table->string('action', 64)->nullable()->index();
            }
            if (! $schema->hasColumn('prompt_result_routing_attempts', 'input_tokens')) {
                $table->unsignedInteger('input_tokens')->nullable();
            }
            if (! $schema->hasColumn('prompt_result_routing_attempts', 'output_tokens')) {
                $table->unsignedInteger('output_tokens')->nullable();
            }
            if (! $schema->hasColumn('prompt_result_routing_attempts', 'total_tokens')) {
                $table->unsignedInteger('total_tokens')->nullable();
            }
        });

        // Add composite index if possible
        try {
            $schema->table('prompt_result_routing_attempts', function (Blueprint $table): void {
                $table->index(['created_at', 'addon', 'module'], 'pr_routing_attempts_time_addon_module_idx');
            });
        } catch (\Throwable) {
            // Index might already exist or driver ignores
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('prompt_result_routing_attempts')) {
            return;
        }

        $schema->table('prompt_result_routing_attempts', function (Blueprint $table) use ($schema): void {
            try {
                $table->dropIndex('pr_routing_attempts_time_addon_module_idx');
            } catch (\Throwable) {}

            foreach ([
                'addon',
                'module',
                'action',
                'input_tokens',
                'output_tokens',
                'total_tokens',
            ] as $column) {
                if ($schema->hasColumn('prompt_result_routing_attempts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
