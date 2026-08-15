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
        $schema = Schema::connection($this->connection);
        if ($schema->hasTable('seo_keyword_classifications')) {
            return;
        }

        $schema->create('seo_keyword_classifications', function (Blueprint $table): void {
            $table->unsignedBigInteger('keyword_id')->primary();
            $table->text('raw_text')->nullable();
            $table->string('normalized_text', 255)->nullable()->index();
            $table->string('folded_text', 255)->nullable()->index();
            $table->string('phrase_kind', 32)->nullable()->index();
            $table->string('seo_intent', 32)->nullable()->index();
            $table->unsignedBigInteger('canonical_keyword_id')->nullable()->index();
            $table->string('cluster_key', 120)->nullable()->index();
            $table->boolean('is_anchor_candidate')->nullable();
            $table->unsignedTinyInteger('anchor_priority')->nullable();
            $table->decimal('classification_confidence', 5, 2)->nullable();
            $table->timestamp('classified_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_keyword_classifications');
    }
};
