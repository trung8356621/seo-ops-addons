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
        Schema::connection($this->connection)->create('seo_rank_keyword_groups', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('created_by')->index();
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('country_code', 8)->default('vn');
            $table->string('language_code', 16)->default('vi');
            $table->string('location')->nullable();
            $table->string('device', 16)->default('desktop');
            $table->string('target_domain')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['created_by', 'is_active']);
        });

        Schema::connection($this->connection)->create('seo_rank_keyword_group_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('group_id')->index();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->timestamps();

            $table->unique(['group_id', 'keyword_id'], 'srkgi_group_keyword_unique');
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_rank_keyword_group_items');
        Schema::connection($this->connection)->dropIfExists('seo_rank_keyword_groups');
    }
};
