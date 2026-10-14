<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Access;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Calendar window for work.stats. No period in the message means all assigned work.
 */
final readonly class WorkStatsWindow
{
    public function __construct(
        public string $mode,
        public ?string $from,
        public ?string $to,
        public string $phrase,
    ) {}

    public static function allTime(): self
    {
        return new self('all', null, null, '');
    }

    public static function fromMessage(string $message, ?DateTimeInterface $now = null): self
    {
        $now = DateTimeImmutable::createFromInterface($now ?? new DateTimeImmutable('now'));
        $text = mb_strtolower($message);

        if (preg_match('/từ\s+(\d{4}-\d{2}-\d{2})\s+đến\s+(\d{4}-\d{2}-\d{2})/u', $text, $iso) === 1) {
            return self::range($iso[1], $iso[2]);
        }

        if (preg_match('/từ\s+(\d{1,2})\/(\d{1,2})\/(\d{4})\s+đến\s+(\d{1,2})\/(\d{1,2})\/(\d{4})/u', $text, $dmy) === 1) {
            return self::range(
                sprintf('%04d-%02d-%02d', (int) $dmy[3], (int) $dmy[2], (int) $dmy[1]),
                sprintf('%04d-%02d-%02d', (int) $dmy[6], (int) $dmy[5], (int) $dmy[4]),
            );
        }

        if (preg_match('/từ\s+tháng\s+(1[0-2]|0?[1-9])\s+đến\s+tháng\s+(1[0-2]|0?[1-9])/u', $text, $span) === 1) {
            $year = (int) $now->format('Y');
            $start = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, (int) $span[1]));
            $end = new DateTimeImmutable(sprintf('%04d-%02d-01', $year, (int) $span[2]));

            return self::range($start->format('Y-m-d'), $end->modify('last day of this month')->format('Y-m-d'));
        }

        if (preg_match('/tháng trước|\bprevious month\b/u', $text) === 1) {
            return self::month($now->modify('first day of previous month'), 'trong tháng trước');
        }

        if (preg_match('/tháng này|\bthis month\b/u', $text) === 1) {
            return self::month($now->modify('first day of this month'), 'trong tháng này');
        }

        if (preg_match('/\b(20\d{2})-(0[1-9]|1[0-2])\b/', $text, $explicit) === 1) {
            $start = new DateTimeImmutable($explicit[0].'-01');

            return self::month($start, 'trong tháng '.$start->format('m/Y'));
        }

        if (preg_match('/tháng\s*(1[0-2]|0?[1-9])\b/u', $text, $named) === 1) {
            $start = new DateTimeImmutable(sprintf('%04d-%02d-01', (int) $now->format('Y'), (int) $named[1]));

            return self::month($start, 'trong tháng '.(int) $named[1]);
        }

        return self::allTime();
    }

    private static function month(DateTimeImmutable $start, string $phrase): self
    {
        $start = $start->modify('first day of this month');

        return new self('month', $start->format('Y-m-d'), $start->modify('last day of this month')->format('Y-m-d'), $phrase);
    }

    private static function range(string $from, string $to): self
    {
        if ($from > $to) {
            [$from, $to] = [$to, $from];
        }

        return new self('range', $from, $to, 'từ '.$from.' đến '.$to);
    }
}
