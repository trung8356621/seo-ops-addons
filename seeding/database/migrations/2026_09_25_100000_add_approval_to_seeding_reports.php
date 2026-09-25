<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Manager review metadata only — does not affect Seeder quota / Topic completion.
 */
return new class extends Migration
{
    protected $connection = 'omi_seeding';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seeding_reports')) {
            return;
        }

        $schema->table('seeding_reports', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('seeding_reports', 'approved_at')) {
                $table->timestamp('approved_at')->nullable()->after('reported_at')->index();
            }
            if (! $schema->hasColumn('seeding_reports', 'approved_by')) {
                $table->unsignedBigInteger('approved_by')->nullable()->after('approved_at')->index();
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seeding_reports')) {
            return;
        }

        $schema->table('seeding_reports', function (Blueprint $table) use ($schema): void {
            if ($schema->hasColumn('seeding_reports', 'approved_by')) {
                $table->dropColumn('approved_by');
            }
            if ($schema->hasColumn('seeding_reports', 'approved_at')) {
                $table->dropColumn('approved_at');
            }
        });
    }
};
