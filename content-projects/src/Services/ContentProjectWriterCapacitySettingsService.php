<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use App\Models\User;
use App\Models\UserMeta;
use App\Models\WpOption;
use App\Services\Users\SeoOpsSystemUser;

/**
 * Canonical writer monthly capacity settings.
 *
 * Global default (WpOption) + optional per-user override (user_meta).
 * Independent from {@see Support\ContentProject\ContentProjectExecutionLimits::MAX_EXECUTION_PROJECT_ITEMS}.
 */
final class ContentProjectWriterCapacitySettingsService
{
    public const OPTION_KEY = 'content_writer_monthly_capacity_settings';

    public const KEY_DEFAULT_CAPACITY = 'content_writer_monthly_default_capacity';

    public const USER_META_KEY = 'seo_content_monthly_capacity';

    public const DEFAULT_CAPACITY = 30;

    public const MIN_CAPACITY = 0;

    public const MAX_CAPACITY = 1000;

    private const CACHE_KEY = 'content_writer_monthly_capacity_settings.v1';

    /** @var array{content_writer_monthly_default_capacity: int}|null */
    private ?array $inMemorySettings = null;

    public static function withDefaults(): self
    {
        $service = new self;
        $service->inMemorySettings = $service->defaultSettings();

        return $service;
    }

    /**
     * @return array{content_writer_monthly_default_capacity: int}
     */
    public function defaultSettings(): array
    {
        return [
            self::KEY_DEFAULT_CAPACITY => self::DEFAULT_CAPACITY,
        ];
    }

    public function defaultMonthlyCapacity(): int
    {
        return $this->getSettings()[self::KEY_DEFAULT_CAPACITY];
    }

    /**
     * @return array{content_writer_monthly_default_capacity: int}
     */
    public function getSettings(): array
    {
        if ($this->inMemorySettings !== null) {
            return $this->normalizeSettings($this->inMemorySettings);
        }

        if (function_exists('cache')) {
            try {
                /** @var array{content_writer_monthly_default_capacity?: int}|null $cached */
                $cached = cache()->get(self::CACHE_KEY);
                if (is_array($cached)) {
                    return $this->normalizeSettings($cached);
                }
            } catch (\Throwable) {
                // Pure PHPUnit / cache unbound
            }
        }

        $stored = [];
        try {
            $raw = WpOption::get(self::OPTION_KEY, []);
            if (is_array($raw)) {
                $stored = $raw;
            }
        } catch (\Throwable) {
            $stored = [];
        }

        $normalized = $this->normalizeSettings($stored);
        if (function_exists('cache')) {
            try {
                cache()->forever(self::CACHE_KEY, $normalized);
            } catch (\Throwable) {
                // ignore
            }
        }

        return $normalized;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{content_writer_monthly_default_capacity: int}
     */
    public function save(array $input): array
    {
        $normalized = $this->normalizeSettings($input);

        try {
            WpOption::set(self::OPTION_KEY, $normalized);
        } catch (\Throwable) {
            // Tests / unbound DB — keep in memory
        }

        $this->inMemorySettings = $normalized;
        if (function_exists('cache')) {
            try {
                cache()->forever(self::CACHE_KEY, $normalized);
            } catch (\Throwable) {
                // ignore
            }
        }

        return $normalized;
    }

    /**
     * Effective monthly capacity: user override ?? global default.
     * Override 0 is intentional (no auto allocation) — not a fallback to default.
     */
    public function capacityForUser(User|int $user): int
    {
        $userId = $user instanceof User ? (int) $user->getKey() : (int) $user;
        if ($userId <= 0 || $this->isSystemUserId($userId)) {
            return 0;
        }

        $override = $this->overrideForUserId($userId);
        if ($override !== null) {
            return $override;
        }

        return $this->defaultMonthlyCapacity();
    }

    /**
     * @param  list<int|string>|array<int|string, mixed>  $userIds
     * @return array<int, int> user_id => effective capacity
     */
    public function capacitiesForUsers(array $userIds): array
    {
        $ids = [];
        $seen = [];
        foreach ($userIds as $raw) {
            $id = (int) $raw;
            if ($id <= 0 || isset($seen[$id]) || $this->isSystemUserId($id)) {
                continue;
            }
            $seen[$id] = true;
            $ids[] = $id;
        }

        if ($ids === []) {
            return [];
        }

        $default = $this->defaultMonthlyCapacity();
        $overrides = $this->overridesForUserIds($ids);
        $result = [];
        foreach ($ids as $id) {
            $result[$id] = array_key_exists($id, $overrides)
                ? $overrides[$id]
                : $default;
        }

        return $result;
    }

    /**
     * Raw override or null when missing / blank (use global default).
     */
    public function overrideForUserId(int $userId): ?int
    {
        if ($userId <= 0) {
            return null;
        }

        try {
            $raw = UserMeta::query()
                ->where('user_id', $userId)
                ->where('meta_key', self::USER_META_KEY)
                ->value('meta_value');
        } catch (\Throwable) {
            return null;
        }

        return $this->parseOverrideValue($raw);
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, int> only users that have an explicit override
     */
    public function overridesForUserIds(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        try {
            $rows = UserMeta::query()
                ->whereIn('user_id', $userIds)
                ->where('meta_key', self::USER_META_KEY)
                ->get(['user_id', 'meta_value']);
        } catch (\Throwable) {
            return [];
        }

        $overrides = [];
        foreach ($rows as $row) {
            $userId = (int) ($row->user_id ?? 0);
            $parsed = $this->parseOverrideValue($row->meta_value ?? null);
            if ($userId <= 0 || $parsed === null) {
                continue;
            }
            $overrides[$userId] = $parsed;
        }

        return $overrides;
    }

    /**
     * Set per-user override. Pass null to clear (fall back to global default).
     *
     * @throws \InvalidArgumentException when value is out of range
     */
    public function setUserOverride(User|int $user, ?int $capacity): void
    {
        $userId = $user instanceof User ? (int) $user->getKey() : (int) $user;
        if ($userId <= 0) {
            throw new \InvalidArgumentException('Invalid user id.');
        }

        if ($capacity !== null && ($capacity < self::MIN_CAPACITY || $capacity > self::MAX_CAPACITY)) {
            throw new \InvalidArgumentException(sprintf(
                'Capacity must be between %d and %d.',
                self::MIN_CAPACITY,
                self::MAX_CAPACITY,
            ));
        }

        if ($this->isSystemUserId($userId)) {
            throw new \InvalidArgumentException('System user capacity cannot be set.');
        }

        if ($capacity === null) {
            $this->clearUserOverride($userId);

            return;
        }

        $model = $user instanceof User ? $user : User::query()->find($userId);
        if (! $model instanceof User) {
            throw new \InvalidArgumentException('User not found.');
        }

        $model->setMeta(self::USER_META_KEY, (string) $capacity);
    }

    public function clearUserOverride(int $userId): void
    {
        if ($userId <= 0) {
            return;
        }

        try {
            UserMeta::query()
                ->where('user_id', $userId)
                ->where('meta_key', self::USER_META_KEY)
                ->delete();
        } catch (\Throwable) {
            // ignore unbound DB
        }
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{content_writer_monthly_default_capacity: int}
     */
    private function normalizeSettings(array $input): array
    {
        $raw = $input[self::KEY_DEFAULT_CAPACITY] ?? self::DEFAULT_CAPACITY;
        $value = is_numeric($raw) ? (int) $raw : self::DEFAULT_CAPACITY;
        if ($value < self::MIN_CAPACITY) {
            $value = self::MIN_CAPACITY;
        }
        if ($value > self::MAX_CAPACITY) {
            $value = self::MAX_CAPACITY;
        }

        return [
            self::KEY_DEFAULT_CAPACITY => $value,
        ];
    }

    private function isSystemUserId(int $userId): bool
    {
        try {
            return SeoOpsSystemUser::isSystemUserId($userId);
        } catch (\Throwable) {
            return false;
        }
    }

    private function parseOverrideValue(mixed $raw): ?int
    {
        if ($raw === null) {
            return null;
        }

        if (is_string($raw)) {
            $trimmed = trim($raw);
            if ($trimmed === '') {
                return null;
            }
            if (! is_numeric($trimmed)) {
                return null;
            }
            $value = (int) $trimmed;
        } elseif (is_int($raw)) {
            $value = $raw;
        } elseif (is_float($raw)) {
            $value = (int) $raw;
        } else {
            return null;
        }

        if ($value < self::MIN_CAPACITY || $value > self::MAX_CAPACITY) {
            return null;
        }

        return $value;
    }
}
