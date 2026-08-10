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
        if (Schema::connection($this->connection)->hasTable('keyword_site_meta')) {
            return;
        }

        Schema::connection($this->connection)->create('keyword_site_meta', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->unsignedBigInteger('site_id')->index();
            $table->text('target_url')->nullable();
            $table->unsignedInteger('search_volume')->nullable();
            $table->decimal('difficulty', 5, 2)->nullable();
            $table->json('metrics')->nullable();
            $table->timestamps();

            $table->unique(['keyword_id', 'site_id'], 'keyword_site_meta_keyword_site_unique');
            $table->foreign('keyword_id')
                ->references('id')
                ->on('keywords')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('keyword_site_meta');
    }
};
