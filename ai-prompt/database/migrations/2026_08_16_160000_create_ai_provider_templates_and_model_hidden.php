<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * api_connections / seo_ai_models live on core mysql (omi_client).
     * Do not run this file on omi_seo_ai.
     */
    protected $connection = 'mysql';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('ai_provider_templates')) {
            $schema->create('ai_provider_templates', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->string('provider_key', 64);
                $table->string('name');
                $table->string('protocol', 32);
                $table->string('schema_version', 16)->default('1.0');
                $table->json('config');
                $table->boolean('is_builtin')->default(false);
                $table->boolean('enabled')->default(true);
                $table->unsignedInteger('revision')->default(1);
                $table->unsignedBigInteger('updated_by')->nullable();
                $table->timestamps();
                $table->unique(['user_id', 'provider_key'], 'ai_provider_templates_user_key_unique');
            });
        }

        if ($schema->hasTable('seo_ai_models') && ! $schema->hasColumn('seo_ai_models', 'is_hidden')) {
            $schema->table('seo_ai_models', function (Blueprint $table): void {
                $table->boolean('is_hidden')->default(false)->after('status');
            });
        }
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);
        if ($schema->hasTable('seo_ai_models') && $schema->hasColumn('seo_ai_models', 'is_hidden')) {
            $schema->table('seo_ai_models', function (Blueprint $table): void {
                $table->dropColumn('is_hidden');
            });
        }
        $schema->dropIfExists('ai_provider_templates');
    }
};
