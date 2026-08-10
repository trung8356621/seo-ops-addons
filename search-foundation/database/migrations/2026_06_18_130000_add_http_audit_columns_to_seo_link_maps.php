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
        if (! Schema::connection($this->connection)->hasTable('seo_link_maps')) {
            return;
        }

        Schema::connection($this->connection)->table('seo_link_maps', function (Blueprint $table): void {
            if (! Schema::connection($this->connection)->hasColumn('seo_link_maps', 'last_http_status')) {
                $table->unsignedSmallInteger('last_http_status')->nullable()->after('status');
            }

            if (! Schema::connection($this->connection)->hasColumn('seo_link_maps', 'last_audited_at')) {
                $table->timestamp('last_audited_at')->nullable()->after('last_http_status');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_link_maps')) {
            return;
        }

        Schema::connection($this->connection)->table('seo_link_maps', function (Blueprint $table): void {
            if (Schema::connection($this->connection)->hasColumn('seo_link_maps', 'last_audited_at')) {
                $table->dropColumn('last_audited_at');
            }

            if (Schema::connection($this->connection)->hasColumn('seo_link_maps', 'last_http_status')) {
                $table->dropColumn('last_http_status');
            }
        });
    }
};
