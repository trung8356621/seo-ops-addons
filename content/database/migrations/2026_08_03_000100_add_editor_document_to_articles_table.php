<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 5A — TipTap JSON canonical editable document on articles.
 * body remains derived HTML compatibility/output.
 */
return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    public function up(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('articles')) {
            return;
        }

        $schema->table('articles', function (Blueprint $table) use ($schema): void {
            if (! $schema->hasColumn('articles', 'editor_document')) {
                $table->json('editor_document')->nullable()->after('body');
            }
            if (! $schema->hasColumn('articles', 'editor_document_schema_version')) {
                $table->unsignedInteger('editor_document_schema_version')->default(1)->after('editor_document');
            }
            if (! $schema->hasColumn('articles', 'editor_document_hash')) {
                $table->string('editor_document_hash', 64)->nullable()->after('editor_document_schema_version');
            }
            if (! $schema->hasColumn('articles', 'editor_document_status')) {
                $table->string('editor_document_status', 32)->nullable()->after('editor_document_hash');
            }
            if (! $schema->hasColumn('articles', 'editor_document_updated_at')) {
                $table->timestamp('editor_document_updated_at')->nullable()->after('editor_document_status');
            }
        });
    }

    public function down(): void
    {
        $schema = Schema::connection($this->connection);

        if (! $schema->hasTable('articles')) {
            return;
        }

        $schema->table('articles', function (Blueprint $table) use ($schema): void {
            foreach ([
                'editor_document_updated_at',
                'editor_document_status',
                'editor_document_hash',
                'editor_document_schema_version',
                'editor_document',
            ] as $column) {
                if ($schema->hasColumn('articles', $column)) {
                    $table->dropColumn($column);
                }
            }
        });
    }
};
