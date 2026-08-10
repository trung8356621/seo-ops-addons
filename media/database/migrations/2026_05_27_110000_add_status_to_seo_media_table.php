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
        Schema::connection($this->connection)->table('seo_media', function (Blueprint $table): void {
            if (! Schema::connection($this->connection)->hasColumn('seo_media', 'status')) {
                $table->string('status', 20)->default('completed')->index();
            }

            if (! Schema::connection($this->connection)->hasColumn('seo_media', 'error_message')) {
                $table->text('error_message')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('seo_media', function (Blueprint $table): void {
            if (Schema::connection($this->connection)->hasColumn('seo_media', 'error_message')) {
                $table->dropColumn('error_message');
            }

            if (Schema::connection($this->connection)->hasColumn('seo_media', 'status')) {
                $table->dropColumn('status');
            }
        });
    }
};
