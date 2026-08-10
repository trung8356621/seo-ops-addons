<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Omnichannel\Addons\SearchFoundation\Models\Keyword;
use Omnichannel\Addons\SearchIntelligence\Models\SeoRankKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoRankKeywordGroupItem;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class SeoRankKeywordGroupService
{
    public function __construct(
        private readonly GoogleSearchConsoleDomainMatcherService $hostNormalizer,
    ) {}

    /**
     * @return list<array{id: int, name: string, label: string, keyword_count: int, country_code: string, device: string, target_domain: string|null}>
     */
    public function listOptionsForUser(int $userId): array
    {
        return $this->accessibleQuery($userId)
            ->active()
            ->withCount('items')
            ->orderBy('name')
            ->get()
            ->map(static fn (SeoRankKeywordGroup $group): array => [
                'id' => (int) $group->id,
                'name' => (string) $group->name,
                'label' => $group->summaryLabel().' — '.((int) ($group->items_count ?? 0)).' '.__('seo-content-ai::filament.rank_group.keywords_short'),
                'keyword_count' => (int) ($group->items_count ?? 0),
                'country_code' => (string) $group->country_code,
                'device' => (string) $group->device,
                'target_domain' => $group->target_domain,
            ])
            ->values()
            ->all();
    }

    public function findAccessibleGroup(int $groupId, int $userId): ?SeoRankKeywordGroup
    {
        return $this->accessibleQuery($userId)
            ->whereKey($groupId)
            ->first();
    }

    /**
     * @param  array{name: string, description?: string|null, country_code?: string, language_code?: string, location?: string|null, device?: string, target_domain?: string|null, keywords_text?: string|null, is_active?: bool}  $data
     */
    public function createGroup(int $userId, array $data): SeoRankKeywordGroup
    {
        SeoAccessControl::guardSeoPanelMutation();

        return DB::connection('omi_seo_ai')->transaction(function () use ($userId, $data): SeoRankKeywordGroup {
            $name = trim((string) ($data['name'] ?? ''));
            if ($name === '') {
                throw new \RuntimeException(__('seo-content-ai::filament.rank_group.name_required'));
            }

            $group = SeoRankKeywordGroup::query()->create([
                'created_by' => $this->resolveOwnerId($userId),
                'name' => $name,
                'description' => filled($data['description'] ?? null) ? trim((string) $data['description']) : null,
                'country_code' => $this->normalizeCountryCode((string) ($data['country_code'] ?? 'vn')),
                'language_code' => $this->normalizeLanguageCode((string) ($data['language_code'] ?? 'vi')),
                'location' => filled($data['location'] ?? null) ? trim((string) $data['location']) : null,
                'device' => $this->normalizeDevice((string) ($data['device'] ?? 'desktop')),
                'target_domain' => $this->normalizeTargetDomain($data['target_domain'] ?? null),
                'is_active' => ($data['is_active'] ?? true) !== false,
            ]);

            $this->syncKeywordsFromText($group, (string) ($data['keywords_text'] ?? ''));

            return $group->fresh(['items']);
        });
    }

    /**
     * @param  array{name?: string, description?: string|null, country_code?: string, language_code?: string, location?: string|null, device?: string, target_domain?: string|null, keywords_text?: string|null, is_active?: bool}  $data
     */
    public function updateGroup(SeoRankKeywordGroup $group, int $userId, array $data): SeoRankKeywordGroup
    {
        SeoAccessControl::guardSeoPanelMutation();
        $this->assertCanMutateGroup($group, $userId);

        return DB::connection('omi_seo_ai')->transaction(function () use ($group, $data): SeoRankKeywordGroup {
            $payload = [];

            if (array_key_exists('name', $data)) {
                $name = trim((string) $data['name']);
                if ($name === '') {
                    throw new \RuntimeException(__('seo-content-ai::filament.rank_group.name_required'));
                }
                $payload['name'] = $name;
            }
            if (array_key_exists('description', $data)) {
                $payload['description'] = filled($data['description']) ? trim((string) $data['description']) : null;
            }
            if (array_key_exists('country_code', $data)) {
                $payload['country_code'] = $this->normalizeCountryCode((string) $data['country_code']);
            }
            if (array_key_exists('language_code', $data)) {
                $payload['language_code'] = $this->normalizeLanguageCode((string) $data['language_code']);
            }
            if (array_key_exists('location', $data)) {
                $payload['location'] = filled($data['location']) ? trim((string) $data['location']) : null;
            }
            if (array_key_exists('device', $data)) {
                $payload['device'] = $this->normalizeDevice((string) $data['device']);
            }
            if (array_key_exists('target_domain', $data)) {
                $payload['target_domain'] = $this->normalizeTargetDomain($data['target_domain']);
            }
            if (array_key_exists('is_active', $data)) {
                $payload['is_active'] = (bool) $data['is_active'];
            }

            if ($payload !== []) {
                $group->fill($payload);
                $group->save();
            }

            if (array_key_exists('keywords_text', $data)) {
                $this->syncKeywordsFromText($group, (string) $data['keywords_text'], replace: true);
            }

            return $group->fresh(['items']);
        });
    }

    public function duplicateGroup(SeoRankKeywordGroup $group, int $userId): SeoRankKeywordGroup
    {
        SeoAccessControl::guardSeoPanelMutation();
        $this->assertCanMutateGroup($group, $userId);

        return DB::connection('omi_seo_ai')->transaction(function () use ($group, $userId): SeoRankKeywordGroup {
            $copy = SeoRankKeywordGroup::query()->create([
                'created_by' => $this->resolveOwnerId($userId),
                'name' => $group->name.' ('.__('seo-content-ai::filament.rank_group.copy_suffix').')',
                'description' => $group->description,
                'country_code' => $group->country_code,
                'language_code' => $group->language_code,
                'location' => $group->location,
                'device' => $group->device,
                'target_domain' => $group->target_domain,
                'is_active' => true,
            ]);

            $keywordIds = $group->items()->pluck('keyword_id')->all();
            $this->attachKeywordIds($copy, $keywordIds);

            return $copy->fresh(['items']);
        });
    }

    public function archiveGroup(SeoRankKeywordGroup $group, int $userId): void
    {
        SeoAccessControl::guardSeoPanelMutation();
        $this->assertCanMutateGroup($group, $userId);

        $group->is_active = false;
        $group->save();
    }

    public function deleteGroup(SeoRankKeywordGroup $group, int $userId): void
    {
        SeoAccessControl::guardSeoPanelMutation();
        $this->assertCanMutateGroup($group, $userId);

        $group->delete();
    }

    /**
     * @param  list<int>  $keywordIds
     * @param  list<int>  $groupIds
     * @return array{added: int, skipped: int}
     */
    public function addKeywordsToGroups(array $keywordIds, array $groupIds, int $userId): array
    {
        SeoAccessControl::guardSeoPanelMutation();

        $keywordIds = array_values(array_unique(array_filter(array_map(intval(...), $keywordIds), static fn (int $id): bool => $id > 0)));
        $groupIds = array_values(array_unique(array_filter(array_map(intval(...), $groupIds), static fn (int $id): bool => $id > 0)));

        if ($keywordIds === [] || $groupIds === []) {
            return ['added' => 0, 'skipped' => 0];
        }

        $groups = $this->accessibleQuery($userId)
            ->whereIn('id', $groupIds)
            ->get();

        $added = 0;
        $skipped = 0;

        foreach ($groups as $group) {
            foreach ($keywordIds as $keywordId) {
                $exists = SeoRankKeywordGroupItem::query()
                    ->where('group_id', $group->id)
                    ->where('keyword_id', $keywordId)
                    ->exists();

                if ($exists) {
                    $skipped++;

                    continue;
                }

                SeoRankKeywordGroupItem::query()->create([
                    'group_id' => $group->id,
                    'keyword_id' => $keywordId,
                ]);
                $added++;
            }
        }

        return ['added' => $added, 'skipped' => $skipped];
    }

    /**
     * @return list<string>
     */
    public function parseKeywordLines(string $text): array
    {
        $lines = preg_split('/\R/u', $text) ?: [];

        return collect($lines)
            ->map(static fn (string $line): string => trim($line))
            ->filter(static fn (string $line): bool => $line !== '')
            ->unique(static fn (string $line): string => mb_strtolower($line))
            ->values()
            ->all();
    }

    public function normalizeTargetDomain(mixed $value): ?string
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        $normalized = $this->hostNormalizer->normalizeHost($value);

        return $normalized !== '' ? $normalized : null;
    }

    /**
     * @return Builder<SeoRankKeywordGroup>
     */
    private function accessibleQuery(int $userId): Builder
    {
        return SeoRankKeywordGroup::query()->accessible();
    }

    private function assertCanMutateGroup(SeoRankKeywordGroup $group, int $userId): void
    {
        if ($this->findAccessibleGroup((int) $group->id, $userId) === null) {
            throw new \RuntimeException(__('seo-content-ai::filament.rank_group.not_accessible'));
        }
    }

    private function resolveOwnerId(int $userId): int
    {
        return SeoAccessControl::shouldScopeToAccountOwner()
            ? SeoAccessControl::accountSiteOwnerId()
            : $userId;
    }

    private function normalizeCountryCode(string $code): string
    {
        $code = strtolower(trim($code));

        return $code !== '' ? $code : 'vn';
    }

    private function normalizeLanguageCode(string $code): string
    {
        $code = strtolower(trim($code));

        return $code !== '' ? $code : 'vi';
    }

    private function normalizeDevice(string $device): string
    {
        $device = strtolower(trim($device));

        return in_array($device, ['desktop', 'mobile'], true) ? $device : 'desktop';
    }

    /**
     * @param  list<int>  $keywordIds
     */
    private function attachKeywordIds(SeoRankKeywordGroup $group, array $keywordIds): void
    {
        foreach ($keywordIds as $keywordId) {
            SeoRankKeywordGroupItem::query()->firstOrCreate([
                'group_id' => $group->id,
                'keyword_id' => $keywordId,
            ]);
        }
    }

    private function syncKeywordsFromText(SeoRankKeywordGroup $group, string $text, bool $replace = false): void
    {
        $phrases = $this->parseKeywordLines($text);
        if ($phrases === []) {
            return;
        }

        if ($replace) {
            $group->items()->delete();
        }

        foreach ($phrases as $phrase) {
            $keyword = Keyword::query()->firstOrCreate(['phrase' => $phrase]);
            SeoRankKeywordGroupItem::query()->firstOrCreate([
                'group_id' => $group->id,
                'keyword_id' => (int) $keyword->id,
            ]);
        }
    }
}
