<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Apply-phase columns for Topic grouping runs (TASK 5). */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_topic_grouping_runs')) {
            return;
        }

        $schema->table('seo_topic_grouping_runs', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('seo_topic_grouping_runs', 'plan_hash')) {
                $table->string('plan_hash', 64)->nullable()->after('input_hash');
            }
            if (! $schema->hasColumn('seo_topic_grouping_runs', 'apply_plan_payload')) {
                $table->json('apply_plan_payload')->nullable()->after('diagnostics');
            }
            if (! $schema->hasColumn('seo_topic_grouping_runs', 'applied_at')) {
                $table->timestamp('applied_at')->nullable()->after('completed_at');
            }
            if (! $schema->hasColumn('seo_topic_grouping_runs', 'apply_error_code')) {
                $table->string('apply_error_code', 64)->nullable()->after('error_message');
            }
            if (! $schema->hasColumn('seo_topic_grouping_runs', 'apply_error_message')) {
                $table->text('apply_error_message')->nullable()->after('apply_error_code');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_topic_grouping_runs')) {
            return;
        }
        $schema->table('seo_topic_grouping_runs', function (Blueprint $table) use ($schema): void {
            foreach (['plan_hash', 'apply_plan_payload', 'applied_at', 'apply_error_code', 'apply_error_message'] as $col) {
                if ($schema->hasColumn('seo_topic_grouping_runs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
