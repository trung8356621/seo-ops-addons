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
        Schema::connection($this->connection)->table('articles', function (Blueprint $table): void {
            if (! Schema::connection($this->connection)->hasColumn('articles', 'is_reviewed')) {
                $table->boolean('is_reviewed')->default(false)->after('status');
            }

            if (! Schema::connection($this->connection)->hasColumn('articles', 'reviewed_at')) {
                $table->timestamp('reviewed_at')->nullable()->after('is_reviewed');
            }
        });
    }

    public function down(): void
    {
        Schema::connection($this->connection)->table('articles', function (Blueprint $table): void {
            $dropColumns = [];

            if (Schema::connection($this->connection)->hasColumn('articles', 'reviewed_at')) {
                $dropColumns[] = 'reviewed_at';
            }

            if (Schema::connection($this->connection)->hasColumn('articles', 'is_reviewed')) {
                $dropColumns[] = 'is_reviewed';
            }

            if ($dropColumns !== []) {
                $table->dropColumn($dropColumns);
            }
        });
    }
};
