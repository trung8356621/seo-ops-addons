<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Support\PublishingQueue;

use Omnichannel\Addons\Publishing\Application\Publishing\PublishingRetryPolicy;

/**
 * User-facing Publishing Queue status labels (no claim/lease/scanner jargon).
 */
final class PublishingQueueStatusLabelBuilder
{
    public const MAX_ATTEMPTS = PublishingRetryPolicy::MAX_ATTEMPTS;

    /**
     * @param  array<string, mixed>  $row
     */
    public static function label(array $row): string
    {
        $state = (string) ($row['publish_state'] ?? PublishingQueueStateClassifier::classify($row)['state']);

        return match ($state) {
            PublishingQueueStateClassifier::PUBLISHING => 'Đang xuất bản',
            PublishingQueueStateClassifier::AWAITING_DELIVERY,
            'awaiting_worker' => 'Đang chuẩn bị',
            PublishingQueueStateClassifier::RETRY_WAIT => 'Thử lại sau',
            PublishingQueueStateClassifier::FAILED => 'Không thể xuất bản',
            PublishingQueueStateClassifier::PUBLISHED => 'Đã xuất bản',
            PublishingQueueStateClassifier::SCHEDULED => 'Đã lên lịch',
            PublishingQueueStateClassifier::NEEDS_ATTENTION => 'Cần xử lý',
            default => 'Chưa lên lịch',
        };
    }
}
