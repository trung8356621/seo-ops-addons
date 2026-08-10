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
        Schema::connection($this->connection)->create('articles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->unsignedBigInteger('site_id')->index();
            $table->foreignId('prompt_result_id')->nullable()->constrained('prompt_results')->nullOnDelete();
            $table->string('title');
            $table->string('slug', 255)->nullable()->index();
            $table->text('excerpt')->nullable();
            $table->longText('body')->nullable();
            $table->json('blocks')->nullable();
            $table->string('language', 16)->default('vi');
            $table->string('status', 32)->default('draft');
            $table->decimal('seo_score', 5, 2)->nullable();
            $table->unsignedBigInteger('wp_post_id')->nullable()->index();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('articles');
    }
};
