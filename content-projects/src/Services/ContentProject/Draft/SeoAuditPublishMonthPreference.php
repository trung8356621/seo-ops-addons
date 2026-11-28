<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\ContentProject\Draft;

use App\Models\User;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectMonthContext;

/**
 * Per-user preference for SEO Audit Sticky "Tháng Publish" (YYYY-MM).
 * Never falls back to current month or Active Month; empty until user selects.
 */
final class SeoAuditPublishMonthPreference
{
    public const META_KEY = 'seo_audit_publish_target_month';

    public static function get(?User $user = null): string
    {
        $user ??= auth()->user();
        if (! $user instanceof User) {
            return '';
        }

        $raw = $user->getMeta(self::META_KEY);
        if (! is_string($raw) || trim($raw) === '') {
            return '';
        }

        $parsed = ContentProjectMonthContext::parseOrNull($raw);
        if ($parsed === null) {
            $user->setMeta(self::META_KEY, '');

            return '';
        }

        return $parsed;
    }

    public static function set(?string $month, ?User $user = null): string
    {
        $parsed = ContentProjectMonthContext::parseOrNull($month);
        $value = $parsed ?? '';
        $user ??= auth()->user();
        if ($user instanceof User) {
            $user->setMeta(self::META_KEY, $value);
        }

        return $value;
    }
}
