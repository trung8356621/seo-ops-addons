<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * V2 execution: source_type, completed counter, explicit single-social topics.
 */
return new class extends Migration
{
    protected $connection = 'omi_seeding';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seeding_topics')) {
            return;
        }

        $schema->table('seeding_topics', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('seeding_topics', 'source_type')) {
                $table->string('source_type', 32)->default('manual')->after('status')->index();
            }
            if (! $schema->hasColumn('seeding_topics', 'completed_comments')) {
                $table->unsignedInteger('completed_comments')->default(0)->after('required_comments_per_user');
            }
            if (! $schema->hasColumn('seeding_topics', 'paused_at')) {
                $table->timestamp('paused_at')->nullable()->after('archived_at');
            }
            if (! $schema->hasColumn('seeding_topics', 'cancelled_at')) {
                $table->timestamp('cancelled_at')->nullable()->after('paused_at');
            }
            if (! $schema->hasColumn('seeding_topics', 'completed_at')) {
                $table->timestamp('completed_at')->nullable()->after('cancelled_at');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seeding_topics')) {
            return;
        }

        $schema->table('seeding_topics', function (Blueprint $table) use ($schema): void {
            foreach (['source_type', 'completed_comments', 'paused_at', 'cancelled_at', 'completed_at'] as $col) {
                if ($schema->hasColumn('seeding_topics', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
