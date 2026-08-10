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
        Schema::connection($this->connection)->create('seo_faqs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('article_id')->index();
            $table->string('question', 500)->comment('Câu hỏi FAQ');
            $table->text('answer')->comment('Câu trả lời chi tiết');
            $table->integer('sort_order')->default(0)->comment('Thứ tự sắp xếp');
            $table->timestamps();

            $table->foreign('article_id')
                ->references('id')
                ->on('articles')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_faqs');
    }
};
