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
        if (! $schema->hasTable('prompts') || $schema->hasColumn('prompts', 'portable_uuid')) {
            return;
        }
        $schema->table('prompts', function (Blueprint $table): void {
            $table->uuid('portable_uuid')->nullable()->unique();
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if ($schema->hasTable('prompts') && $schema->hasColumn('prompts', 'portable_uuid')) {
            $schema->table('prompts', function (Blueprint $table): void {
                $table->dropColumn('portable_uuid');
            });
        }
    }
};
