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
        if (! $schema->hasTable('seo_projects')) {
            return;
        }

        if ($schema->hasColumn('seo_projects', 'meta')) {
            return;
        }

        $schema->table('seo_projects', function (Blueprint $table): void {
            $table->json('meta')->nullable()->after('description');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_projects') || ! $schema->hasColumn('seo_projects', 'meta')) {
            return;
        }

        $schema->table('seo_projects', function (Blueprint $table): void {
            $table->dropColumn('meta');
        });
    }
};
