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
        if (! $schema->hasTable('seo_media')) {
            return;
        }

        // Later migration drops these; slim legacy schema already without them.
        if (! $schema->hasColumn('seo_media', 'site_id')
            && ! $schema->hasColumn('seo_media', 'wp_attachment_id')) {
            return;
        }

        $schema->table('seo_media', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('seo_media', 'wp_attachment_id')) {
                $col = $table->unsignedBigInteger('wp_attachment_id')->nullable()->index();
                if ($schema->hasColumn('seo_media', 'site_id')) {
                    $col->after('site_id');
                }
            }

            if (! $schema->hasColumn('seo_media', 'wp_synced_at')) {
                $col = $table->timestamp('wp_synced_at')->nullable();
                if ($schema->hasColumn('seo_media', 'source')) {
                    $col->after('source');
                }
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('seo_media')) {
            return;
        }

        $schema->table('seo_media', function (Blueprint $table) use ($schema): void {
            foreach (['wp_attachment_id', 'wp_synced_at'] as $column) {
                if ($schema->hasColumn('seo_media', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
