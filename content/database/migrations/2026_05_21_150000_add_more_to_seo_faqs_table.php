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
        if (Schema::connection($this->connection)->hasColumn('seo_faqs', 'more')) {
            return;
        }

        Schema::connection($this->connection)->table('seo_faqs', function (Blueprint $table): void {
            $table->longText('more')->nullable()->after('answer')->comment('HTML chen giữa câu hỏi và câu trả lời');
        });
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasColumn('seo_faqs', 'more')) {
            return;
        }

        Schema::connection($this->connection)->table('seo_faqs', function (Blueprint $table): void {
            $table->dropColumn('more');
        });
    }
};
