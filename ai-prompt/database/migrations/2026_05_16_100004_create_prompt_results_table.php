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
        Schema::connection($this->connection)->create('prompt_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prompt_id')->constrained('prompts')->cascadeOnDelete();
            $table->foreignId('entity_id')->nullable()->constrained('entities')->nullOnDelete();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('status', 32)->default('pending');
            $table->json('input_snapshot')->nullable();
            $table->longText('output_text')->nullable();
            $table->json('token_usage')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('prompt_results');
    }
};
