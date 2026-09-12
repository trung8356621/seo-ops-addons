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
        if (! $schema->hasTable('prompt_result_routing_attempts')) {
            return;
        }

        try {
            $schema->table('prompt_result_routing_attempts', function (Blueprint $table): void {
                $table->unsignedBigInteger('prompt_result_id')->nullable()->change();
            });
        } catch (\Throwable) {}
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if (! $schema->hasTable('prompt_result_routing_attempts')) {
            return;
        }

        try {
            $schema->table('prompt_result_routing_attempts', function (Blueprint $table): void {
                $table->unsignedBigInteger('prompt_result_id')->nullable(false)->change();
            });
        } catch (\Throwable) {}
    }
};
