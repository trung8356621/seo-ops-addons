<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Models;

use Omnichannel\Addons\SearchFoundation\Models\Concerns\BelongsToOnDefaultConnection;
use App\Models\Site;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Schema;

class SeoMedia extends Model
{
    use BelongsToOnDefaultConnection;

    /** @var list<string> */
    public const AUXILIARY_META_FIELDS = [
        'site_id',
        'article_id',
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

    private static ?bool $hasMetaTable = null;

    /** @var array<string, mixed> */
    protected array $metaAttributes = [];

    /** @var array<string, mixed> */
    protected array $loadedMetaValues = [];

    protected bool $metaValuesLoaded = false;

    protected $connection = 'omi_seo_ai';

    protected $table = 'seo_media';

    protected $guarded = [];

    public function newEloquentBuilder($query): SeoMediaBuilder
    {
        return new SeoMediaBuilder($query);
    }

    protected function casts(): array
    {
        return [
            'site_id' => 'integer',
            'wp_attachment_id' => 'integer',
            'wp_synced_at' => 'datetime',
            'prompt_id' => 'integer',
            'prompt_variables' => 'array',
        ];
    }

    public static function isAuxiliaryMetaField(string $field): bool
    {
        return in_array($field, self::AUXILIARY_META_FIELDS, true);
    }

    public static function normalizeMetaValueForQuery(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if ($value === '') {
            return '';
        }

        if (is_array($value)) {
            return json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        $text = trim((string) $value);

        return $text !== '' ? $text : null;
    }

    public function isAiGenerationJob(): bool
    {
        $source = strtolower(trim((string) $this->source));

        return in_array($source, ['ai_prompt', 'ai_video_prompt'], true);
    }

    public function aiToolType(): string
    {
        return strtolower(trim((string) $this->source)) === 'ai_video_prompt' ? 'video' : 'image';
    }

    public static function placeholderLoadingUrl(): string
    {
        return '/assets/images/placeholder-loading.svg';
    }

    public static function placeholderLoadingPath(): string
    {
        return 'assets/images/placeholder-loading.svg';
    }

    public function article(): BelongsTo
    {
        return $this->belongsTo(SeoArticle::class, 'primary_article_id');
    }

    public function mediaMetas(): HasMany
    {
        return $this->hasMany(SeoMediaMeta::class, 'media_id');
    }

    public function site(): BelongsTo
    {
        return $this->belongsToOnDefaultConnection(Site::class, 'site_id');
    }

    public function publicUrl(): string
    {
        $path = ltrim(str_replace('\\', '/', (string) $this->path), '/');
        if ($path !== '') {
            return '/storage/' . $path;
        }

        $url = (string) $this->url;
        if (str_starts_with($url, '/storage/')) {
            return $url;
        }

        return $url;
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    public function fill(array $attributes): static
    {
        $metaPayload = self::extractAuxiliaryMetaPayload($attributes);
        foreach ($metaPayload as $key => $value) {
            $this->setAttribute($key, $value);
        }

        return parent::fill(array_diff_key($attributes, $metaPayload));
    }

    public function setAttribute($key, $value): static
    {
        if (is_string($key) && self::isAuxiliaryMetaField($key)) {
            if ($key === 'article_id') {
                $ids = self::normalizeArticleIds($value);
                $this->metaAttributes[$key] = $ids === [] ? null : $ids;
                $this->loadedMetaValues[$key] = $this->metaAttributes[$key];

                return $this;
            }

            if ($key === 'site_id') {
                $siteId = (int) $value;
                $this->metaAttributes[$key] = $siteId > 0 ? $siteId : null;
                $this->loadedMetaValues[$key] = $this->metaAttributes[$key];

                return $this;
            }

            $this->metaAttributes[$key] = $value;
            $this->loadedMetaValues[$key] = $value;

            return $this;
        }

        return parent::setAttribute($key, $value);
    }

    public function getAttribute($key): mixed
    {
        if (is_string($key) && self::isAuxiliaryMetaField($key)) {
            return $this->getAuxiliaryMetaAttribute($key);
        }

        return parent::getAttribute($key);
    }

    protected static function booted(): void
    {
        static::saved(function (SeoMedia $media): void {
            $media->syncAuxiliaryMeta();
        });

        static::deleted(function (SeoMedia $media): void {
            if (! self::hasMetaTable()) {
                return;
            }

            $media->mediaMetas()->delete();
        });
    }

    public function syncAuxiliaryMeta(): void
    {
        if (! self::hasMetaTable() || ! $this->exists) {
            return;
        }

        $payload = $this->resolveAuxiliaryMetaPayloadForSync();
        if ($payload === []) {
            return;
        }

        self::syncAuxiliaryMetaForRows([(int) $this->getKey()], $payload);
    }

    /**
     * @return array<string, mixed>
     */
    private function resolveAuxiliaryMetaPayloadForSync(): array
    {
        $payload = [];

        foreach (self::AUXILIARY_META_FIELDS as $field) {
            if (array_key_exists($field, $this->metaAttributes)) {
                $payload[$field] = $this->metaAttributes[$field];
            }
        }

        return $payload;
    }

    /**
     * @param  list<int>  $mediaIds
     * @param  array<string, mixed>  $payload
     */
    public static function syncAuxiliaryMetaForRows(array $mediaIds, array $payload): void
    {
        if (! self::hasMetaTable()) {
            return;
        }

        $mediaIds = array_values(array_unique(array_map(static fn ($id): int => max(0, (int) $id), $mediaIds)));
        $mediaIds = array_values(array_filter($mediaIds, static fn (int $id): bool => $id > 0));
        if ($mediaIds === []) {
            return;
        }

        $now = now();
        $upserts = [];
        $deleteKeys = [];

        foreach (self::AUXILIARY_META_FIELDS as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            $value = $field === 'article_id'
                ? self::normalizeArticleIdsAsMetaValue($payload[$field])
                : self::normalizeMetaValue($payload[$field]);
            if ($value === null) {
                $deleteKeys[] = $field;
                continue;
            }

            foreach ($mediaIds as $mediaId) {
                $upserts[] = [
                    'media_id' => $mediaId,
                    'meta_key' => $field,
                    'meta_value' => $value,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        if ($deleteKeys !== []) {
            SeoMediaMeta::query()
                ->whereIn('media_id', $mediaIds)
                ->whereIn('meta_key', $deleteKeys)
                ->delete();
        }

        if ($upserts !== []) {
            SeoMediaMeta::query()->upsert(
                $upserts,
                ['media_id', 'meta_key'],
                ['meta_value', 'updated_at'],
            );
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function extractAuxiliaryMetaPayload(array $payload): array
    {
        $metaPayload = [];
        foreach (self::AUXILIARY_META_FIELDS as $field) {
            if (! array_key_exists($field, $payload)) {
                continue;
            }

            $metaPayload[$field] = $payload[$field];
        }

        return $metaPayload;
    }

    private function getAuxiliaryMetaAttribute(string $key): mixed
    {
        if (array_key_exists($key, $this->metaAttributes)) {
            return $this->castMetaAttribute($key, $this->metaAttributes[$key]);
        }

        $this->ensureMetaValuesLoaded();

        $value = $this->loadedMetaValues[$key] ?? null;

        return $this->castMetaAttribute($key, $value);
    }

    private function ensureMetaValuesLoaded(): void
    {
        if ($this->metaValuesLoaded) {
            return;
        }

        $this->metaValuesLoaded = true;

        if (! self::hasMetaTable() || ! $this->exists) {
            return;
        }

        if ($this->relationLoaded('mediaMetas')) {
            foreach ($this->mediaMetas as $meta) {
                $this->loadedMetaValues[(string) $meta->meta_key] = $meta->meta_value;
            }

            return;
        }

        $rows = $this->mediaMetas()
            ->whereIn('meta_key', self::AUXILIARY_META_FIELDS)
            ->get(['meta_key', 'meta_value']);

        foreach ($rows as $meta) {
            $this->loadedMetaValues[(string) $meta->meta_key] = $meta->meta_value;
        }
    }

    private function castMetaAttribute(string $key, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if ($key === 'site_id') {
            return (int) $value;
        }

        if ($key === 'article_id') {
            return self::normalizeArticleIds($value);
        }

        if ($key === 'prompt_variables') {
            if (is_array($value)) {
                return $value;
            }

            if (is_string($value) && $value !== '') {
                $decoded = json_decode($value, true);

                return is_array($decoded) ? $decoded : null;
            }

            return null;
        }

        if ($key === 'wp_attachment_id' || $key === 'prompt_id') {
            return (int) $value;
        }

        if ($key === 'wp_synced_at') {
            return $this->asDateTime($value);
        }

        return $value;
    }

    private static function normalizeMetaValue(mixed $value): ?string
    {
        return self::normalizeMetaValueForQuery($value);
    }

    private static function normalizeArticleIdsAsMetaValue(mixed $value): ?string
    {
        $ids = self::normalizeArticleIds($value);
        if ($ids === []) {
            return null;
        }

        return json_encode($ids, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    /**
     * @return list<int>
     */
    public static function normalizeArticleIds(mixed $value): array
    {
        $raw = [];

        if (is_array($value)) {
            $raw = $value;
        } elseif (is_string($value)) {
            $trimmed = trim($value);
            if ($trimmed !== '' && str_starts_with($trimmed, '[')) {
                $decoded = json_decode($trimmed, true);
                if (is_array($decoded)) {
                    $raw = $decoded;
                }
            } elseif ($trimmed !== '') {
                $raw = [$trimmed];
            }
        } elseif ($value !== null) {
            $raw = [$value];
        }

        $ids = [];
        foreach ($raw as $id) {
            $num = (int) $id;
            if ($num > 0) {
                $ids[] = $num;
            }
        }

        return array_values(array_unique($ids));
    }

    public function firstArticleId(): ?int
    {
        $ids = $this->article_id;
        if (! is_array($ids) || $ids === []) {
            return null;
        }

        $first = (int) ($ids[0] ?? 0);

        return $first > 0 ? $first : null;
    }

    public function getPrimaryArticleIdAttribute(): ?int
    {
        return $this->firstArticleId();
    }

    private static function hasMetaTable(): bool
    {
        if (self::$hasMetaTable !== null) {
            return self::$hasMetaTable;
        }

        self::$hasMetaTable = Schema::connection('omi_seo_ai')->hasTable('seo_media_meta');

        return self::$hasMetaTable;
    }
}
