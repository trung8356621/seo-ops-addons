<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SiteSync\Services\Progress;

use App\Core\Operations\LongRunningProgress;
use Omnichannel\Addons\Content\Support\SystemDateTime;
use Carbon\Carbon;

final class SiteSyncProgressCopy
{
    public static function elapsedLabel(?string $startedAt): ?string
    {
        if ($startedAt === null || $startedAt === '') {
            return null;
        }
        try {
            $seconds = max(0, (int) Carbon::parse($startedAt)->diffInSeconds(now()));
        } catch (\Throwable) {
            return null;
        }

        $minutes = intdiv($seconds, 60);
        $remain = $seconds % 60;
        if ($minutes <= 0) {
            return 'Đã chạy: '.$remain.' giây';
        }

        return 'Đã chạy: '.$minutes.' phút '.$remain.' giây';
    }

    public static function lastActivityLabel(?string $iso): ?string
    {
        if ($iso === null || $iso === '') {
            return null;
        }
        $relative = SystemDateTime::formatRelative($iso);

        return $relative !== null ? 'Hoạt động gần nhất: '.$relative : null;
    }

    public static function retryLabel(?int $attempt, ?int $max): ?string
    {
        if ($attempt === null || $attempt <= 1) {
            return null;
        }
        if ($max !== null && $max > 0) {
            return 'Đang thử lại · Lần '.$attempt.'/'.$max;
        }

        return 'Đang thử lại · Lần '.$attempt;
    }

    public static function stepMarker(string $status, bool $isActive): string
    {
        if ($status === 'failed') {
            return '✕';
        }
        if (in_array($status, ['completed', 'skipped'], true)) {
            return '✓';
        }
        if ($isActive || $status === 'running') {
            return '→';
        }

        return '○';
    }

    /**
     * @param  array<string, mixed>  $metrics
     */
    public static function runningHeadline(LongRunningProgress $progress, string $phaseLabel): string
    {
        $stepBit = $progress->totalSteps > 0
            ? 'Bước '.$progress->step.'/'.$progress->totalSteps.' · '.$phaseLabel
            : $phaseLabel;
        $pct = $progress->percentage();
        $countBit = '';
        if ($progress->current !== null && $progress->total !== null && $progress->total > 0) {
            $countBit = number_format($progress->current).' / '.number_format($progress->total);
            if ($pct !== null) {
                $countBit .= ' · '.$pct.'%';
            }
        } elseif ($progress->current !== null) {
            $countBit = number_format($progress->current).' bản ghi';
        }

        return trim('Đang đồng bộ website · '.$stepBit.($countBit !== '' ? ' · '.$countBit : ''));
    }
}
