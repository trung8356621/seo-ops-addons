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
        if (! $schema->hasTable('seeding_topics')) {
            return;
        }

        if ($schema->hasColumn('seeding_topics', 'archived_at')) {
            return;
        }

        $schema->table('seeding_topics', function (Blueprint $table): void {
            $table->timestamp('archived_at')->nullable()->after('published_at');
            $table->index(['site_id', 'archived_at'], 'seeding_topics_site_archived_idx');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seeding_topics') || ! $schema->hasColumn('seeding_topics', 'archived_at')) {
            return;
        }

        $schema->table('seeding_topics', function (Blueprint $table): void {
            $table->dropIndex('seeding_topics_site_archived_idx');
            $table->dropColumn('archived_at');
        });
    }
};
