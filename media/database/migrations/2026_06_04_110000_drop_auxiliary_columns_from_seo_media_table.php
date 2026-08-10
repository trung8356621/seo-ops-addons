<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    /** @var list<string> */
    private array $columns = [
        'wp_attachment_id',
        'wp_synced_at',
        'prompt_id',
        'prompt_variables',
        'editor_block_id',
        'status',
        'error_message',
        'ai_generator',
        'alt_text',
    ];

    public function up(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_media')) {
            return;
        }

        Schema::connection($this->connection)->table('seo_media', function (Blueprint $table): void {
            foreach ($this->columns as $column) {
                if (Schema::connection($this->connection)->hasColumn('seo_media', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }

    public function down(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_media')) {
            return;
        }

        Schema::connection($this->connection)->table('seo_media', function (Blueprint $table): void {
            if (! Schema::connection($this->connection)->hasColumn('seo_media', 'wp_attachment_id')) {
                $table->unsignedBigInteger('wp_attachment_id')->nullable()->after('site_id')->index();
            }

            if (! Schema::connection($this->connection)->hasColumn('seo_media', 'prompt_id')) {
                $table->unsignedBigInteger('prompt_id')->nullable()->after('article_id')->index();
            }

            if (! Schema::connection($this->connection)->hasColumn('seo_media', 'prompt_variables')) {
                $table->json('prompt_variables')->nullable()->after('prompt_id');
            }

            if (! Schema::connection($this->connection)->hasColumn('seo_media', 'editor_block_id')) {
                $table->string('editor_block_id', 64)->nullable()->index()->after('prompt_variables');
            }

            if (! Schema::connection($this->connection)->hasColumn('seo_media', 'alt_text')) {
                $table->string('alt_text')->nullable()->after('source');
            }

            if (! Schema::connection($this->connection)->hasColumn('seo_media', 'ai_generator')) {
                $table->string('ai_generator', 120)->nullable()->after('source')->index();
            }

            if (! Schema::connection($this->connection)->hasColumn('seo_media', 'wp_synced_at')) {
                $table->timestamp('wp_synced_at')->nullable()->after('source');
            }

            if (! Schema::connection($this->connection)->hasColumn('seo_media', 'status')) {
                $table->string('status', 20)->default('completed')->index();
            }

            if (! Schema::connection($this->connection)->hasColumn('seo_media', 'error_message')) {
                $table->text('error_message')->nullable();
            }
        });
    }
};
