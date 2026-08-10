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

        if ($schema->hasTable('prompts') && $schema->hasColumn('prompts', 'type')) {
            $schema->table('prompts', function (Blueprint $table): void {
                $table->dropColumn('type');
            });
        }

        if (! $schema->hasTable('seo_tasks')) {
            $schema->create('seo_tasks', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('name');
                $table->text('description')->nullable();
                $table->json('flow_data')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_tasks');

        Schema::connection($this->connection)->table('prompts', function (Blueprint $table) {
            if (! Schema::connection($this->connection)->hasColumn('prompts', 'type')) {
                $table->string('type', 64)->nullable()->after('name');
            }
        });
    }
};
