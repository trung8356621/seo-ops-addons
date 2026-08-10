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
        Schema::connection($this->connection)->table('keywords', function (Blueprint $table) {
            if (! Schema::connection($this->connection)->hasColumn('keywords', 'parent_id')) {
                $table->unsignedBigInteger('parent_id')
                    ->nullable()
                    ->index()
                    ->after('site_id')
                    ->comment('Trỏ về từ khóa chính của cụm');

                $table->foreign('parent_id')
                    ->references('id')
                    ->on('keywords')
                    ->cascadeOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('keywords', function (Blueprint $table) {
            if (Schema::connection($this->connection)->hasColumn('keywords', 'parent_id')) {
                $table->dropForeign(['parent_id']);
                $table->dropColumn('parent_id');
            }
        });
    }
};
