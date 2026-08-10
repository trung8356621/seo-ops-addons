<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Persist Spec SemVer hook_version (e.g. 0.1.0). Phase 1 int 1 → 0.1.0.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('prompts')) {
            return;
        }

        if (! Schema::connection($this->connection)->hasColumn('prompts', 'hook_version')) {
            Schema::connection($this->connection)->table('prompts', function ($table): void {
                $table->string('hook_version', 32)->nullable()->after('hook_key');
            });

            return;
        }

        $driver = Schema::connection($this->connection)->getConnection()->getDriverName();
        if ($driver === 'mysql') {
            DB::connection($this->connection)->statement(
                'ALTER TABLE `prompts` MODIFY `hook_version` VARCHAR(32) NULL',
            );
        } elseif ($driver === 'pgsql') {
            DB::connection($this->connection)->statement(
                'ALTER TABLE prompts ALTER COLUMN hook_version TYPE VARCHAR(32) USING hook_version::text',
            );
        }
        // sqlite: affinity is flexible; values updated below.

        DB::connection($this->connection)->table('prompts')
            ->where(function ($query): void {
                $query->where('hook_version', '1')->orWhere('hook_version', 1);
            })
            ->update(['hook_version' => '0.1.0']);
    }

    public function down(): void
    {
        // SemVer strings cannot safely shrink back to unsigned int — leave as string.
    }
};
