<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchFoundation\Services;

use Omnichannel\Addons\SearchFoundation\Models\Tag;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class TagPersistenceService
{
    public function normalizeName(string $name): string
    {
        return trim(preg_replace('/\s+/u', ' ', $name) ?? $name);
    }

    public function findByName(string $name): ?Tag
    {
        $normalized = $this->normalizeName($name);
        if ($normalized === '') {
            return null;
        }

        return Tag::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($normalized)])
            ->first();
    }

    public function nameExists(string $name, ?int $ignoreTagId = null): bool
    {
        $normalized = $this->normalizeName($name);
        if ($normalized === '') {
            return false;
        }

        $query = Tag::query()
            ->whereRaw('LOWER(TRIM(name)) = ?', [mb_strtolower($normalized)]);

        if ($ignoreTagId !== null && $ignoreTagId > 0) {
            $query->whereKeyNot($ignoreTagId);
        }

        return $query->exists();
    }

    public function findOrCreate(string $name): Tag
    {
        $existing = $this->findByName($name);
        if ($existing !== null) {
            return $existing;
        }

        return $this->create($name);
    }

    /**
     * @deprecated Tags are global; $siteId is ignored.
     */
    public function findOrCreateForSite(int $siteId, string $name): Tag
    {
        return $this->findOrCreate($name);
    }

    public function create(string $name): Tag
    {
        $normalized = $this->normalizeName($name);
        if ($normalized === '') {
            throw new InvalidArgumentException(__('seo-content-ai::filament.tag.name_required'));
        }

        if ($this->nameExists($normalized)) {
            throw new InvalidArgumentException(__('seo-content-ai::filament.tag.name_unique'));
        }

        return Tag::query()->create([
            'name' => $normalized,
            'slug' => $this->resolveUniqueSlug($normalized),
        ]);
    }

    /**
     * @deprecated Use create() — tags are global.
     */
    public function createForSite(int $siteId, string $name): Tag
    {
        return $this->create($name);
    }

    public function resolveUniqueSlug(string $name): string
    {
        $baseSlug = Str::slug($name);
        if ($baseSlug === '') {
            $baseSlug = 'tag';
        }

        $slug = $baseSlug;
        $suffix = 2;

        while (Tag::query()->where('slug', $slug)->exists()) {
            $slug = $baseSlug.'-'.$suffix;
            $suffix++;
        }

        return $slug;
    }
}
