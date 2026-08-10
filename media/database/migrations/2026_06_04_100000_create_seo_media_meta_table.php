<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    protected $connection = 'omi_seo_ai';

    /** @var list<string> */
    private array $metaFields = [
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
        if (! Schema::connection($this->connection)->hasTable('seo_media_meta')) {
            Schema::connection($this->connection)->create('seo_media_meta', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('media_id')->constrained('seo_media')->cascadeOnDelete();
                $table->string('meta_key', 191);
                $table->longText('meta_value')->nullable();
                $table->timestamps();

                $table->unique(['media_id', 'meta_key']);
                $table->index(['meta_key', 'media_id']);
            });
        }

        $this->backfillMeta();
    }

    public function down(): void
    {
        Schema::connection($this->connection)->dropIfExists('seo_media_meta');
    }

    private function backfillMeta(): void
    {
        if (! Schema::connection($this->connection)->hasTable('seo_media')
            || ! Schema::connection($this->connection)->hasTable('seo_media_meta')) {
            return;
        }

        DB::connection($this->connection)
            ->table('seo_media')
            ->orderBy('id')
            ->chunkById(200, function ($rows): void {
                $now = now();
                $upserts = [];

                foreach ($rows as $row) {
                    $mediaId = (int) ($row->id ?? 0);
                    if ($mediaId <= 0) {
                        continue;
                    }

                    foreach ($this->metaFields as $field) {
                        $raw = $row->{$field} ?? null;
                        $value = $this->normalizeMetaValue($field, $raw);
                        if ($value === null) {
                            continue;
                        }

                        $upserts[] = [
                            'media_id' => $mediaId,
                            'meta_key' => $field,
                            'meta_value' => $value,
                            'created_at' => $now,
                            'updated_at' => $now,
                        ];
                    }
                }

                if ($upserts !== []) {
                    DB::connection($this->connection)
                        ->table('seo_media_meta')
                        ->upsert($upserts, ['media_id', 'meta_key'], ['meta_value', 'updated_at']);
                }
            });
    }

    private function normalizeMetaValue(string $field, mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($field === 'prompt_variables') {
            $json = is_string($value) ? trim($value) : json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

            return $json !== '' ? $json : null;
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }
};

