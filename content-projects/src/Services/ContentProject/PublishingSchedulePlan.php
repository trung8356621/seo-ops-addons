<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject;

use Carbon\Carbon;
use InvalidArgumentException;

/**
 * Deterministic schedule plan — preview and apply share the same object.
 *
 * @phpstan-type ItemMap array<int, string>
 */
final class PublishingSchedulePlan
{
    /**
     * @param  list<int>  $eligibleIds
     * @param  list<string>  $slots  UTC ISO-8601, same order as eligibleIds
     * @param  array<int, string>  $itemScheduleMap  task_id => UTC ISO-8601
     * @param  list<array{id: int, reason: string}>  $excluded
     */
    public function __construct(
        public readonly array $eligibleIds,
        public readonly array $slots,
        public readonly array $itemScheduleMap,
        public readonly array $excluded,
        public readonly string $timezone,
        public readonly ?string $blocked = null,
        public readonly ?int $suggestedMaxInterval = null,
        public readonly ?string $firstPublishAt = null,
        public readonly ?string $lastPublishAt = null,
    ) {}

    /**
     * @param  list<int>  $eligibleIds
     * @param  list<Carbon>  $slotCarbons  UTC instants
     * @param  list<array{id: int, reason: string}>  $excluded
     */
    public static function fromSlots(
        array $eligibleIds,
        array $slotCarbons,
        array $excluded,
        string $timezone,
        ?string $blocked = null,
        ?int $suggestedMaxInterval = null,
    ): self {
        if (count($eligibleIds) !== count($slotCarbons) && $blocked === null && $slotCarbons !== []) {
            throw new InvalidArgumentException('Schedule plan slot count must match eligible ids.');
        }

        $slots = [];
        $map = [];
        foreach ($eligibleIds as $index => $id) {
            $carbon = $slotCarbons[$index] ?? null;
            if (! $carbon instanceof Carbon) {
                break;
            }
            $iso = $carbon->copy()->utc()->toIso8601String();
            $slots[] = $iso;
            $map[(int) $id] = $iso;
        }

        return new self(
            eligibleIds: array_values(array_map('intval', $eligibleIds)),
            slots: $slots,
            itemScheduleMap: $map,
            excluded: $excluded,
            timezone: $timezone,
            blocked: $blocked,
            suggestedMaxInterval: $suggestedMaxInterval,
            firstPublishAt: $slots[0] ?? null,
            lastPublishAt: $slots !== [] ? $slots[array_key_last($slots)] : null,
        );
    }

    public static function empty(string $timezone, array $excluded = [], ?string $blocked = null, ?int $suggestedMax = null): self
    {
        return new self(
            eligibleIds: [],
            slots: [],
            itemScheduleMap: [],
            excluded: $excluded,
            timezone: $timezone,
            blocked: $blocked,
            suggestedMaxInterval: $suggestedMax,
        );
    }

    /**
     * @return array{
     *     scheduled: int,
     *     slots: list<string>,
     *     item_schedule_map: array<int, string>,
     *     eligible_ids: list<int>,
     *     excluded: list<array{id: int, reason: string}>,
     *     first_publish_at: string|null,
     *     last_publish_at: string|null,
     *     timezone: string,
     *     blocked: string|null,
     *     suggested_max_interval: int|null
     * }
     */
    public function toArray(int $scheduled = 0): array
    {
        return [
            'scheduled' => $scheduled,
            'slots' => $this->slots,
            'item_schedule_map' => $this->itemScheduleMap,
            'eligible_ids' => $this->eligibleIds,
            'excluded' => $this->excluded,
            'first_publish_at' => $this->firstPublishAt,
            'last_publish_at' => $this->lastPublishAt,
            'timezone' => $this->timezone,
            'blocked' => $this->blocked,
            'suggested_max_interval' => $this->suggestedMaxInterval,
        ];
    }

    public function utcForItem(int $taskId): ?Carbon
    {
        $iso = $this->itemScheduleMap[$taskId] ?? null;
        if (! is_string($iso) || $iso === '') {
            return null;
        }

        return Carbon::parse($iso)->utc();
    }
}
