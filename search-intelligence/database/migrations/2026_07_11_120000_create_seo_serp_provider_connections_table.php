<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'mysql';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);
        if ($schema->hasTable('seo_serp_provider_connections')) {
            return;
        }

        $schema->create('seo_serp_provider_connections', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable()->index();
            $table->string('provider', 32)->index();
            $table->string('name');
            $table->text('api_key')->nullable();
            $table->string('status')->default('not_configured');
            $table->string('default_country', 8)->nullable();
            $table->string('default_language', 16)->nullable();
            $table->string('default_location')->nullable();
            $table->string('default_device', 16)->default('desktop');
            $table->unsignedSmallInteger('result_depth')->default(100);
            $table->boolean('is_global')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamp('last_rank_check_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_serp_provider_connections');
    }
};
