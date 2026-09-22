<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotency for share topic — prevent duplicate topics on retry / lost response.
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
            if (! $schema->hasColumn('seeding_topics', 'share_idempotency_key')) {
                $table->string('share_idempotency_key', 128)->nullable()->after('installation_id');
                $table->unique(
                    ['installation_id', 'share_idempotency_key', 'social_platform'],
                    'seeding_topics_install_share_idem_platform_uq',
                );
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
            if ($schema->hasColumn('seeding_topics', 'share_idempotency_key')) {
                $table->dropUnique('seeding_topics_install_share_idem_platform_uq');
                $table->dropColumn('share_idempotency_key');
            }
        });
    }
};
