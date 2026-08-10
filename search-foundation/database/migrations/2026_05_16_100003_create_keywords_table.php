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
        Schema::connection($this->connection)->create('keywords', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('site_id')->index();
            $table->string('phrase');
            $table->unsignedInteger('search_volume')->nullable();
            $table->decimal('difficulty', 5, 2)->nullable();
            $table->json('metrics')->nullable();
            $table->timestamps();

            $table->index(['site_id', 'phrase']);
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('keywords');
    }
};
