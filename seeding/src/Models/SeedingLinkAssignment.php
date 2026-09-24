<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Omnichannel\Addons\Seeding\Support\SeedingServiceConfig;

/**
 * Installation-scoped shared seeding link assignment (FLOW B SoT).
 * Independent of Topic/feed (FLOW A). Seeder daily progress is localStorage-only.
 */
class SeedingLinkAssignment extends Model
{
    protected $connection = SeedingServiceConfig::CONNECTION;

    protected $table = 'seeding_link_assignments';

    /** @var list<string> */
    protected $fillable = [
        'installation_id',
        'owner_user_id',
        'title',
        'url',
        'target_per_day',
        'is_active',
    ];

    protected $casts = [
        'owner_user_id' => 'integer',
        'target_per_day' => 'integer',
        'is_active' => 'boolean',
    ];

    /**
     * Stable public id used as Seeder local progress key (daily_link_progress).
     */
    public function publicId(): string
    {
        return 'assign:'.(int) $this->id;
    }

    /**
     * @return array{id: string, title: string, label: string, url: string, target_per_day: int, is_active: bool, owner_user_id: int, created_at: ?string, updated_at: ?string}
     */
    public function toApiArray(): array
    {
        $title = trim((string) ($this->title ?? ''));

        return [
            'id' => $this->publicId(),
            'assignment_id' => (int) $this->id,
            'title' => $title,
            'label' => $title,
            'url' => (string) $this->url,
            'target_per_day' => max(1, (int) $this->target_per_day),
            'is_active' => (bool) $this->is_active,
            'owner_user_id' => (int) $this->owner_user_id,
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * @deprecated Legacy Topic.links_json snapshot shape only — not used by current shared assignment flow.
     *
     * @return array{id: string, title: ?string, label: ?string, url: string, target_per_day: int}
     */
    public function toTopicSnapshot(): array
    {
        $title = trim((string) ($this->title ?? ''));

        return [
            'id' => $this->publicId(),
            'title' => $title !== '' ? $title : null,
            'label' => $title !== '' ? $title : null,
            'url' => (string) $this->url,
            'target_per_day' => max(1, (int) $this->target_per_day),
        ];
    }

    /** @param  Builder<static>  $query */
    public function scopeForInstallation(Builder $query, string $installationId): Builder
    {
        return $query->where('installation_id', $installationId);
    }

    /** @param  Builder<static>  $query */
    public function scopeOwnedBy(Builder $query, int $userId): Builder
    {
        return $query->where('owner_user_id', $userId);
    }

    /** @param  Builder<static>  $query */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
