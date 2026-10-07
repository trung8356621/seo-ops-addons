<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\KeywordGroup;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Omnichannel\Addons\SearchIntelligence\Enums\KeywordGroup\KeywordGroupSource;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroup;
use Omnichannel\Addons\SearchIntelligence\Models\SeoKeywordGroupKeyword;
use Omnichannel\Addons\SearchIntelligence\Support\KeywordGroupSchema;

/**
 * Manual Group edits are Laravel business truth.
 *
 * Any rename or membership change promotes that Group to source=manual.
 * source=manual or is_locked=true is excluded from semantic replacement.
 * Unlock does not revert source.
 */
final class KeywordGroupManualService
{
    public function create(int $siteId, string $name, array $keywordIds = []): SeoKeywordGroup
    {
        $this->assertReady($siteId);
        $name = $this->normalizeName($name);

        return DB::connection('omi_seo_ai')->transaction(function () use ($siteId, $name, $keywordIds): SeoKeywordGroup {
            $group = SeoKeywordGroup::query()->create([
                'site_id' => $siteId,
                'name' => $name,
                'source' => KeywordGroupSource::MANUAL,
                'representative_keyword_id' => null,
                'is_locked' => false,
            ]);

            foreach ($keywordIds as $keywordId) {
                $this->assignKeyword($siteId, (int) $keywordId, (int) $group->id);
            }

            $group->refresh();

            return $group;
        });
    }

    public function rename(int $siteId, int $groupId, string $name): SeoKeywordGroup
    {
        $this->assertReady($siteId);
        $group = $this->requireGroup($siteId, $groupId);
        $group->name = $this->normalizeName($name);
        $group->source = KeywordGroupSource::MANUAL;
        $group->save();

        return $group;
    }

    public function setLocked(int $siteId, int $groupId, bool $locked): SeoKeywordGroup
    {
        $this->assertReady($siteId);
        $group = $this->requireGroup($siteId, $groupId);
        $group->is_locked = $locked;
        $group->save();

        return $group;
    }

    public function assignKeyword(int $siteId, int $keywordId, ?int $targetGroupId): void
    {
        $this->assertReady($siteId);
        if ($keywordId <= 0) {
            throw new InvalidArgumentException('invalid_keyword_group_assignment');
        }

        DB::connection('omi_seo_ai')->transaction(function () use ($siteId, $keywordId, $targetGroupId): void {
            $current = SeoKeywordGroupKeyword::query()
                ->where('site_id', $siteId)
                ->where('keyword_id', $keywordId)
                ->lockForUpdate()
                ->first();

            $target = null;
            if ($targetGroupId !== null && $targetGroupId > 0) {
                $target = SeoKeywordGroup::query()
                    ->where('site_id', $siteId)
                    ->whereKey($targetGroupId)
                    ->lockForUpdate()
                    ->first();
                if (! $target instanceof SeoKeywordGroup) {
                    throw new InvalidArgumentException('keyword_group_not_found');
                }
            }

            $targetId = $target instanceof SeoKeywordGroup ? (int) $target->id : 0;
            if ($current !== null && (int) $current->group_id === $targetId) {
                return;
            }

            if ($current !== null) {
                $old = SeoKeywordGroup::query()
                    ->where('site_id', $siteId)
                    ->whereKey((int) $current->group_id)
                    ->lockForUpdate()
                    ->first();
                $current->delete();
                if ($old instanceof SeoKeywordGroup) {
                    $this->promoteToManual($old);
                    $this->repairRepresentative($old);
                }
            }

            if (! $target instanceof SeoKeywordGroup) {
                return;
            }

            SeoKeywordGroupKeyword::query()->create([
                'site_id' => $siteId,
                'group_id' => $target->id,
                'keyword_id' => $keywordId,
                'source' => KeywordGroupSource::MANUAL,
                'similarity_score' => null,
            ]);
            $this->promoteToManual($target);
            if ((int) ($target->representative_keyword_id ?? 0) <= 0) {
                $target->representative_keyword_id = $keywordId;
                $target->save();
            }
        });
    }

    private function assertReady(int $siteId): void
    {
        if ($siteId <= 0 || ! KeywordGroupSchema::tablesReady()) {
            throw new InvalidArgumentException('keyword_groups_unavailable');
        }
    }

    private function requireGroup(int $siteId, int $groupId): SeoKeywordGroup
    {
        $group = SeoKeywordGroup::query()
            ->where('site_id', $siteId)
            ->whereKey($groupId)
            ->first();
        if (! $group instanceof SeoKeywordGroup) {
            throw new InvalidArgumentException('keyword_group_not_found');
        }

        return $group;
    }

    private function normalizeName(string $name): string
    {
        $name = trim($name);
        if ($name === '') {
            throw new InvalidArgumentException('keyword_group_name_required');
        }

        return mb_substr($name, 0, 255);
    }

    private function promoteToManual(SeoKeywordGroup $group): void
    {
        if (KeywordGroupSource::isManual($group->source)) {
            return;
        }

        $group->source = KeywordGroupSource::MANUAL;
        $group->save();
    }

    private function repairRepresentative(SeoKeywordGroup $group): void
    {
        $group->refresh();
        $ids = SeoKeywordGroupKeyword::query()
            ->where('site_id', (int) $group->site_id)
            ->where('group_id', (int) $group->id)
            ->orderBy('keyword_id')
            ->pluck('keyword_id');
        $current = (int) ($group->representative_keyword_id ?? 0);
        if ($current > 0 && $ids->contains($current)) {
            return;
        }

        $group->representative_keyword_id = $ids->isEmpty() ? null : (int) $ids->first();
        $group->save();
    }
}
