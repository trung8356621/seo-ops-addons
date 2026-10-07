<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Enums;

/**
 * Canonical UI surfaces for operational notifications.
 *
 * Notification Center is always the default. Operational Alert Hook is opt-in.
 * Stable project term for the high-visibility surface: "Operational Alert Hook"
 * (internal id: operational-alert / enum value operational_alert).
 */
enum NotificationDisplaySurface: string
{
    case NotificationCenter = 'notification_center';
    case OperationalAlert = 'operational_alert';

    public const HOOK_ID = 'operational-alert';

    /**
     * @return list<self>
     */
    public static function defaults(): array
    {
        return [self::NotificationCenter];
    }

    /**
     * Notification Center + Operational Alert Hook.
     *
     * @return list<self>
     */
    public static function withOperationalAlertHook(): array
    {
        return [self::NotificationCenter, self::OperationalAlert];
    }

    /**
     * @param  list<self|string>  $surfaces
     * @return list<string>
     */
    public static function normalize(array $surfaces): array
    {
        if ($surfaces === []) {
            return array_map(static fn (self $s): string => $s->value, self::defaults());
        }

        $values = [];
        foreach ($surfaces as $surface) {
            $case = $surface instanceof self
                ? $surface
                : self::tryFrom((string) $surface);
            if (! $case instanceof self) {
                continue;
            }
            $values[$case->value] = $case->value;
        }

        if ($values === []) {
            return array_map(static fn (self $s): string => $s->value, self::defaults());
        }

        // Notification Center remains implicit and first whenever any surface is set.
        $ordered = [self::NotificationCenter->value => self::NotificationCenter->value];
        foreach ($values as $value) {
            $ordered[$value] = $value;
        }

        return array_values($ordered);
    }

    /**
     * @param  list<string>  $surfaces
     */
    public static function includesOperationalAlertHook(array $surfaces): bool
    {
        return in_array(self::OperationalAlert->value, $surfaces, true);
    }

    /**
     * @param  array<string, mixed>  $data  Filament notification data payload
     * @return list<string>
     */
    public static function fromNotificationData(array $data): array
    {
        $ops = is_array($data['operational'] ?? null) ? $data['operational'] : [];
        $raw = $ops['display_surfaces'] ?? null;
        if (! is_array($raw)) {
            return array_map(static fn (self $s): string => $s->value, self::defaults());
        }

        return self::normalize($raw);
    }
}
