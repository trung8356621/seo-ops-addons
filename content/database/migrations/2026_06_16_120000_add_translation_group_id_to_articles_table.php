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
        Schema::connection($this->connection)->table('articles', function (Blueprint $table): void {
            if (! Schema::connection($this->connection)->hasColumn('articles', 'translation_group_id')) {
                $table->unsignedBigInteger('translation_group_id')
                    ->nullable()
                    ->after('language')
                    ->index();
            }
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('articles', function (Blueprint $table): void {
            if (Schema::connection($this->connection)->hasColumn('articles', 'translation_group_id')) {
                $table->dropIndex(['translation_group_id']);
                $table->dropColumn('translation_group_id');
            }
        });
    }
};
