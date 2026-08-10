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
        if (! $schema->hasTable('prompts')) {
            return;
        }

        $schema->table('prompts', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('prompts', 'name')) {
                $col = $table->string('name')->nullable();
                if ($schema->hasColumn('prompts', 'site_id')) {
                    $col->after('site_id');
                } elseif ($schema->hasColumn('prompts', 'user_id')) {
                    $col->after('user_id');
                }
            }

            if (! $schema->hasColumn('prompts', 'type')) {
                $col = $table->string('type', 64)->nullable();
                if ($schema->hasColumn('prompts', 'name')) {
                    $col->after('name');
                }
            }

            if (! $schema->hasColumn('prompts', 'is_active')) {
                $col = $table->boolean('is_active')->default(true);
                if ($schema->hasColumn('prompts', 'type')) {
                    $col->after('type');
                } elseif ($schema->hasColumn('prompts', 'name')) {
                    $col->after('name');
                }
            }
        });

        if ($schema->hasColumn('prompts', 'name') && $schema->hasColumn('prompts', 'title')) {
            DB::connection($this->connection)->statement(
                'UPDATE prompts SET name = title WHERE name IS NULL OR name = \'\''
            );
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('prompts')) {
            return;
        }

        $schema->table('prompts', function (Blueprint $table) use ($schema): void {
            foreach (['name', 'type', 'is_active'] as $column) {
                if ($schema->hasColumn('prompts', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
