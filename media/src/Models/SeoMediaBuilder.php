<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Arr;

class SeoMediaBuilder extends Builder
{
    /**
     * @param  array<string, mixed>  $values
     */
    public function update(array $values): int
    {
        $metaPayload = SeoMedia::extractAuxiliaryMetaPayload($values);
        $coreValues = array_diff_key($values, $metaPayload);

        $mediaIds = [];
        if ($metaPayload !== []) {
            $keyColumn = $this->qualifyColumn($this->getModel()->getKeyName());
            $mediaIds = (clone $this)
                ->pluck($keyColumn)
                ->map(static fn ($id): int => (int) $id)
                ->all();
        }

        $updated = $coreValues === [] ? 0 : parent::update($coreValues);

        if ($metaPayload !== [] && $mediaIds !== []) {
            SeoMedia::syncAuxiliaryMetaForRows($mediaIds, $metaPayload);
        }

        return max($updated, $metaPayload !== [] && $mediaIds !== [] ? count($mediaIds) : 0);
    }

    /**
     * @param  \Closure|string|array<int|string, mixed>|(\Illuminate\Contracts\Database\Query\Expression)  $column
     * @param  mixed  $operator
     * @param  mixed  $value
     */
    public function where($column, $operator = null, $value = null, $boolean = 'and'): static
    {
        if ($this->shouldRouteWhereToMeta($column, $operator, $value)) {
            return $this->whereMeta((string) $column, $operator, $value, (string) $boolean);
        }

        return parent::where($column, $operator, $value, $boolean);
    }

    /**
     * @param  \Closure|string|array<int|string, mixed>|(\Illuminate\Contracts\Database\Query\Expression)  $column
     * @param  mixed  $operator
     * @param  mixed  $value
     */
    public function orWhere($column, $operator = null, $value = null): static
    {
        if ($this->shouldRouteWhereToMeta($column, $operator, $value)) {
            return $this->whereMeta((string) $column, $operator, $value, 'or');
        }

        return parent::orWhere($column, $operator, $value);
    }

    /**
     * @param  \Illuminate\Contracts\Database\Query\Expression|model-property<TModel>|string|array<int, model-property<TModel>|string>  $columns
     */
    public function whereNull($columns, $boolean = 'and', $not = false): static
    {
        if ($not || ! is_string($columns) || ! SeoMedia::isAuxiliaryMetaField($columns)) {
            return parent::whereNull($columns, $boolean, $not);
        }

        return $this->whereMetaNull($columns, (string) $boolean);
    }

    /**
     * @param  \Illuminate\Contracts\Database\Query\Expression|model-property<TModel>|string|array<int, model-property<TModel>|string>  $columns
     */
    public function orWhereNull($column): static
    {
        if (! is_string($column) || ! SeoMedia::isAuxiliaryMetaField($column)) {
            return parent::orWhereNull($column);
        }

        return $this->whereMetaNull($column, 'or');
    }

    /**
     * @param  \Illuminate\Contracts\Database\Query\Expression|model-property<TModel>|string|array<int, model-property<TModel>|string>  $columns
     */
    public function whereNotNull($columns, $boolean = 'and'): static
    {
        if (! is_string($columns) || ! SeoMedia::isAuxiliaryMetaField($columns)) {
            return parent::whereNotNull($columns, $boolean);
        }

        return $this->whereMetaNotNull($columns, (string) $boolean);
    }

    /**
     * @param  \Illuminate\Contracts\Database\Query\Expression|model-property<TModel>|string|array<int, model-property<TModel>|string>  $columns
     */
    public function orWhereNotNull($column): static
    {
        if (! is_string($column) || ! SeoMedia::isAuxiliaryMetaField($column)) {
            return parent::orWhereNotNull($column);
        }

        return $this->whereMetaNotNull($column, 'or');
    }

    /**
     * @param  \Illuminate\Contracts\Database\Query\Expression|model-property<TModel>|string  $column
     * @param  mixed  $values
     */
    public function whereIn($column, $values, $boolean = 'and', $not = false): static
    {
        if ($not || ! is_string($column) || ! SeoMedia::isAuxiliaryMetaField($column)) {
            return parent::whereIn($column, $values, $boolean, $not);
        }

        return $this->whereMetaIn($column, Arr::wrap($values), (string) $boolean);
    }

    /**
     * @param  \Illuminate\Contracts\Database\Query\Expression|model-property<TModel>|string  $column
     * @param  mixed  $values
     */
    public function orWhereIn($column, $values, $not = false): static
    {
        return $this->whereIn($column, $values, 'or', $not);
    }

    public function whereMeta(string $key, mixed $operator, mixed $value = null, string $boolean = 'and'): static
    {
        if (func_num_args() === 2) {
            $value = $operator;
            $operator = '=';
        }

        if ($key === 'article_id') {
            return $this->whereArticleIdMeta($operator, $value, $boolean);
        }

        if (in_array(strtolower((string) $operator), ['like', 'not like'], true)) {
            return $this->whereMetaLike($key, (string) $value, (string) $operator, $boolean);
        }

        $this->whereExists(function (QueryBuilder $query) use ($key, $operator, $value): void {
            $query->from('seo_media_meta')
                ->whereColumn('seo_media_meta.media_id', $this->qualifyColumn('id'))
                ->where('seo_media_meta.meta_key', $key)
                ->where('seo_media_meta.meta_value', $operator, SeoMedia::normalizeMetaValueForQuery($value));
        }, $boolean);

        return $this;
    }

    public function whereMetaLike(string $key, string $value, string $operator = 'like', string $boolean = 'and'): static
    {
        $this->whereExists(function (QueryBuilder $query) use ($key, $value, $operator): void {
            $query->from('seo_media_meta')
                ->whereColumn('seo_media_meta.media_id', $this->qualifyColumn('id'))
                ->where('seo_media_meta.meta_key', $key)
                ->where('seo_media_meta.meta_value', $operator, $value);
        }, $boolean);

        return $this;
    }

    /**
     * @param  list<int|string>  $values
     */
    public function whereMetaIn(string $key, array $values, string $boolean = 'and'): static
    {
        if ($key === 'article_id') {
            $articleIds = [];
            foreach ($values as $value) {
                $articleIds = array_merge($articleIds, SeoMedia::normalizeArticleIds($value));
            }
            $articleIds = array_values(array_unique($articleIds));
            if ($articleIds === []) {
                return $this->whereRaw('0 = 1', [], $boolean);
            }

            $this->where(function (self $query) use ($articleIds): void {
                foreach ($articleIds as $index => $articleId) {
                    $query->whereArticleIdMeta('=', $articleId, $index === 0 ? 'and' : 'or');
                }
            }, null, null, $boolean);

            return $this;
        }

        $normalized = [];
        foreach ($values as $value) {
            $text = SeoMedia::normalizeMetaValueForQuery($value);
            if ($text !== null) {
                $normalized[] = $text;
            }
        }

        if ($normalized === []) {
            return $this->whereRaw('0 = 1', [], $boolean);
        }

        $this->whereExists(function (QueryBuilder $query) use ($key, $normalized): void {
            $query->from('seo_media_meta')
                ->whereColumn('seo_media_meta.media_id', $this->qualifyColumn('id'))
                ->where('seo_media_meta.meta_key', $key)
                ->whereIn('seo_media_meta.meta_value', $normalized);
        }, $boolean);

        return $this;
    }

    public function whereMetaNull(string $key, string $boolean = 'and'): static
    {
        $this->where(function (self $query) use ($key): void {
            $query->whereNotExists(function (QueryBuilder $sub) use ($key): void {
                $sub->from('seo_media_meta')
                    ->whereColumn('seo_media_meta.media_id', $this->qualifyColumn('id'))
                    ->where('seo_media_meta.meta_key', $key)
                    ->whereNotNull('seo_media_meta.meta_value')
                    ->where('seo_media_meta.meta_value', '!=', '');
            });
        }, null, null, $boolean);

        return $this;
    }

    public function whereMetaNotNull(string $key, string $boolean = 'and'): static
    {
        $this->whereExists(function (QueryBuilder $query) use ($key): void {
            $query->from('seo_media_meta')
                ->whereColumn('seo_media_meta.media_id', $this->qualifyColumn('id'))
                ->where('seo_media_meta.meta_key', $key)
                ->whereNotNull('seo_media_meta.meta_value')
                ->where('seo_media_meta.meta_value', '!=', '');
        }, $boolean);

        return $this;
    }

    /**
     * Compare a core column (e.g. updated_at) against a datetime meta value.
     */
    public function whereColumnAfterMeta(string $column, string $operator, string $metaKey, string $boolean = 'and'): static
    {
        $operator = match (strtolower(trim($operator))) {
            '>', '>=', '<', '<=' => strtolower(trim($operator)),
            default => '>',
        };

        $qualifiedColumn = $this->qualifyColumn($column);

        $this->whereExists(function (QueryBuilder $query) use ($qualifiedColumn, $operator, $metaKey): void {
            $query->from('seo_media_meta')
                ->whereColumn('seo_media_meta.media_id', $this->qualifyColumn('id'))
                ->where('seo_media_meta.meta_key', $metaKey)
                ->whereNotNull('seo_media_meta.meta_value')
                ->where('seo_media_meta.meta_value', '!=', '')
                ->whereRaw("{$qualifiedColumn} {$operator} CAST(seo_media_meta.meta_value AS DATETIME)");
        }, $boolean);

        return $this;
    }

    public function orWhereColumnAfterMeta(string $column, string $operator, string $metaKey): static
    {
        return $this->whereColumnAfterMeta($column, $operator, $metaKey, 'or');
    }

    private function shouldRouteWhereToMeta(mixed $column, mixed &$operator, mixed &$value): bool
    {
        if (! is_string($column) || ! SeoMedia::isAuxiliaryMetaField($column)) {
            return false;
        }

        $operatorText = is_string($operator) ? strtolower($operator) : '';

        // Support Laravel's 2-arg where('article_id', 1013) — value is passed as $operator.
        if ($operatorText === '' || ! in_array($operatorText, ['=', '!=', '<>', '>', '>=', '<', '<=', 'like', 'not like'], true)) {
            $value = $operator;
            $operator = '=';
        }

        return true;
    }

    private function whereArticleIdMeta(mixed $operator, mixed $value, string $boolean = 'and'): static
    {
        $articleIds = SeoMedia::normalizeArticleIds($value);
        $operator = strtolower((string) $operator);

        if ($articleIds === []) {
            if (in_array($operator, ['!=', '<>'], true)) {
                return $this;
            }

            return $this->whereRaw('0 = 1', [], $boolean);
        }

        $matchesAny = function (QueryBuilder $query) use ($articleIds): void {
            $query->from('seo_media_meta')
                ->whereColumn('seo_media_meta.media_id', $this->qualifyColumn('id'))
                ->where('seo_media_meta.meta_key', 'article_id')
                ->where(function (QueryBuilder $inner) use ($articleIds): void {
                    foreach ($articleIds as $index => $articleId) {
                        $jsonNeedle = (string) json_encode($articleId, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

                        $innerMethod = $index === 0 ? 'whereRaw' : 'orWhereRaw';
                        $inner->{$innerMethod}('JSON_CONTAINS(seo_media_meta.meta_value, ?)', [$jsonNeedle]);

                        $innerScalarMethod = $index === 0 ? 'orWhere' : 'orWhere';
                        $inner->{$innerScalarMethod}('seo_media_meta.meta_value', '=', (string) $articleId);
                    }
                });
        };

        if (in_array($operator, ['!=', '<>'], true)) {
            $this->whereNotExists($matchesAny, $boolean);

            return $this;
        }

        $this->whereExists($matchesAny, $boolean);

        return $this;
    }
}
