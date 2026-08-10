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
        Schema::connection($this->connection)->dropIfExists('entity_results');

        if (Schema::connection($this->connection)->hasColumn('prompt_results', 'entity_id')) {
            Schema::connection($this->connection)->table('prompt_results', function (Blueprint $table): void {
                $table->dropForeign(['entity_id']);
                $table->dropColumn('entity_id');
            });
        }

        Schema::connection($this->connection)->dropIfExists('entities');
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasTable('entities')) {
            Schema::connection($this->connection)->create('entities', function (Blueprint $table): void {
                $table->id();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('name');
                $table->string('slug', 128)->nullable()->index();
                $table->string('provider', 64)->default('openai');
                $table->longText('credential_data')->nullable();
                $table->json('parsed_data')->nullable();
                $table->json('keywords')->nullable();
                $table->json('settings')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::connection($this->connection)->hasColumn('prompt_results', 'entity_id')) {
            Schema::connection($this->connection)->table('prompt_results', function (Blueprint $table): void {
                $table->foreignId('entity_id')->nullable()->after('prompt_id')->constrained('entities')->nullOnDelete();
            });
        }

        if (! Schema::connection($this->connection)->hasTable('entity_results')) {
            Schema::connection($this->connection)->create('entity_results', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('entity_id')->constrained('entities')->cascadeOnDelete();
                $table->foreignId('prompt_result_id')->nullable()->constrained('prompt_results')->nullOnDelete();
                $table->unsignedBigInteger('user_id')->index();
                $table->unsignedBigInteger('site_id')->index();
                $table->string('status', 32)->default('pending');
                $table->json('request_payload')->nullable();
                $table->json('response_payload')->nullable();
                $table->string('external_id', 191)->nullable()->index();
                $table->unsignedInteger('duration_ms')->nullable();
                $table->text('error_message')->nullable();
                $table->timestamps();
            });
        }
    }
};
