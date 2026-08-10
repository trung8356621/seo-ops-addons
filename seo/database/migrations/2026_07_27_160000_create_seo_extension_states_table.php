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
        if (! Schema::connection($this->connection)->hasTable('seo_extension_states')) {
            Schema::connection($this->connection)->create('seo_extension_states', function (Blueprint $table): void {
                $table->id();
                $table->string('extension_id')->unique();
                $table->boolean('enabled')->default(true);
                $table->string('status', 32)->default('healthy')->index();
                $table->string('installed_version', 32)->nullable();
                $table->text('last_error')->nullable();
                $table->json('health_payload')->nullable();
                $table->timestamps();
            });
        }
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_extension_states');
    }
};
