<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_gsc_master_connections')) {
            return;
        }

        $schema->table('seo_gsc_master_connections', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('seo_gsc_master_connections', 'oauth_client_id')) {
                $col = $table->string('oauth_client_id')->nullable();
                if ($schema->hasColumn('seo_gsc_master_connections', 'account_email')) {
                    $col->after('account_email');
                }
            }

            if (! $schema->hasColumn('seo_gsc_master_connections', 'oauth_client_secret')) {
                $col = $table->text('oauth_client_secret')->nullable();
                if ($schema->hasColumn('seo_gsc_master_connections', 'oauth_client_id')) {
                    $col->after('oauth_client_id');
                }
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_gsc_master_connections')) {
            return;
        }

        $schema->table('seo_gsc_master_connections', function (Blueprint $table) use ($schema): void {
            $drop = [];
            foreach (['oauth_client_id', 'oauth_client_secret'] as $column) {
                if ($schema->hasColumn('seo_gsc_master_connections', $column)) {
                    $drop[] = $column;
                }
            }
            if ($drop !== []) {
                $table->dropColumn($drop);
            }
        });
    }
};
