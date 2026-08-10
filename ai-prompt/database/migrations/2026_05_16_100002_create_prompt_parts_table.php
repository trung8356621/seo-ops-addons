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
        Schema::connection($this->connection)->create('prompt_parts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prompt_id')->constrained('prompts')->cascadeOnDelete();
            $table->unsignedInteger('position')->default(0);
            $table->string('role', 32)->default('user');
            $table->longText('content');
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('prompt_parts');
    }
};
