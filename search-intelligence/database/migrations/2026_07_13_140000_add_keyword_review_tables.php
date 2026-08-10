<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        Schema::connection($this->connection)->create('keyword_review_reasons', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('workspace_id')->index();
            $table->string('name');
            $table->string('default_severity', 16);
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['workspace_id', 'is_active', 'sort_order']);
        });

        Schema::connection($this->connection)->create('keyword_review_histories', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('keyword_id')->index();
            $table->unsignedBigInteger('article_id')->nullable()->index();
            $table->string('from_status', 16);
            $table->string('to_status', 16);
            $table->unsignedBigInteger('reason_id')->nullable()->index();
            $table->string('severity', 16);
            $table->text('note')->nullable();
            $table->string('source', 32);
            $table->unsignedBigInteger('reviewed_by');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['keyword_id', 'created_at']);
        });

        Schema::connection($this->connection)->table('keywords', function (Blueprint $table): void {
            $table->string('review_status', 16)->default('active')->after('parent_id');
            $table->unsignedBigInteger('review_reason_id')->nullable()->after('review_status');
            $table->text('review_note')->nullable()->after('review_reason_id');
            $table->unsignedBigInteger('reviewed_by')->nullable()->after('review_note');
            $table->timestamp('reviewed_at')->nullable()->after('reviewed_by');

            $table->index('review_status');
            $table->index(['review_status', 'reviewed_at']);
        });

        DB::connection($this->connection)
            ->table('keyword_meta')
            ->where('meta_key', 'quality_flags')
            ->delete();
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('keywords', function (Blueprint $table): void {
            $table->dropIndex(['review_status']);
            $table->dropIndex(['review_status', 'reviewed_at']);
            $table->dropColumn([
                'review_status',
                'review_reason_id',
                'review_note',
                'reviewed_by',
                'reviewed_at',
            ]);
        });

        Schema::connection($this->connection)->dropIfExists('keyword_review_histories');
        Schema::connection($this->connection)->dropIfExists('keyword_review_reasons');
    }
};
