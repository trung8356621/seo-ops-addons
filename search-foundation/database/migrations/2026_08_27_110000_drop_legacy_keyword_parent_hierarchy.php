<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasColumn('keywords', 'parent_id')) {
            return;
        }

        $remaining = (int) DB::connection($this->connection)
            ->table('keywords')
            ->whereNotNull('parent_id')
            ->where('parent_id', '>', 0)
            ->count();

        if ($remaining > 0) {
            throw new RuntimeException(
                "Cannot drop keywords.parent_id: {$remaining} row(s) still have parent_id set. Flatten hierarchy first."
            );
        }

        $schema->table('keywords', function (Blueprint $table): void {
            $table->dropForeign(['parent_id']);
            $table->dropColumn('parent_id');
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if ($schema->hasColumn('keywords', 'parent_id')) {
            return;
        }

        $schema->table('keywords', function (Blueprint $table): void {
            $table->unsignedBigInteger('parent_id')
                ->nullable()
                ->index()
                ->after('type')
                ->comment('Historical: legacy keyword hierarchy (retired)');

            $table->foreign('parent_id')
                ->references('id')
                ->on('keywords')
                ->cascadeOnDelete();
        });
    }
};
