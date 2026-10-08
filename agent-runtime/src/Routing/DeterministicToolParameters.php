<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Routing;

use DateTimeImmutable;
use DateTimeInterface;

/**
 * Explicit dates, counts, references and language. Nothing here calls a model.
 */
final class DeterministicToolParameters
{
    public function __construct(private readonly ?DateTimeInterface $now = null) {}

    /**
     * @return array{parameters: array<string, int|string>, clarification: ?string, language: string}
     */
    public function extract(string $message): array
    {
        $language = $this->language($message);
        $parameters = [];

        if (preg_match('/(\d{1,3})\s*(?:hoặc|hay|\bor\b)\s*(\d{1,3})/iu', $message, $choice) === 1
            && (int) $choice[1] !== (int) $choice[2]) {
            $text = $language === 'vi'
                ? 'Có hai giới hạn số lượng khác nhau. Hãy chọn một khoảng, ví dụ 30-50.'
                : 'Two different counts were given. Choose one range, for example 30-50.';

            return ['parameters' => [], 'clarification' => $text, 'language' => $language];
        }

        if (preg_match('/(\d{1,3})\s*(?:-|–|—|đến|\bto\b)\s*(\d{1,3})/iu', $message, $range) === 1) {
            $low = (int) $range[1];
            $high = (int) $range[2];
            $parameters['limit_min'] = min($low, $high);
            $parameters['limit_max'] = max($low, $high);
        } elseif (preg_match('/(\d{1,3})\s*bài/iu', $message, $single) === 1) {
            $count = (int) $single[1];
            $parameters['limit_min'] = $count;
            $parameters['limit_max'] = $count;
        }

        $period = $this->period($message);
        if ($period !== null) {
            $parameters['period'] = $period;
        }

        if (preg_match('/\barticle:\d+\b/i', $message, $article) === 1) {
            $parameters['article_ref'] = $article[0];
        }
        if (preg_match('/\btopic:[\w:-]+\b/i', $message, $topic) === 1) {
            $parameters['topic_ref'] = $topic[0];
        }

        return ['parameters' => $parameters, 'clarification' => null, 'language' => $language];
    }

    public function language(string $message): string
    {
        if (preg_match('/[àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ]/iu', $message) === 1) {
            return 'vi';
        }
        if (preg_match('/\b(tháng|bài|từ khóa|cần|gợi ý|không)\b/iu', $message) === 1) {
            return 'vi';
        }

        return 'en';
    }

    private function period(string $message): ?string
    {
        if (preg_match('/\b(20\d{2})-(0[1-9]|1[0-2])\b/', $message, $explicit) === 1) {
            return $explicit[0];
        }

        $now = $this->now ?? new DateTimeImmutable('now');
        $year = (int) $now->format('Y');
        $month = (int) $now->format('n');

        if (preg_match('/tháng\s*(1[0-2]|0?[1-9])\b/iu', $message, $named) === 1) {
            return sprintf('%04d-%02d', $year, (int) $named[1]);
        }
        if (preg_match('/tháng này|\bthis month\b/iu', $message) === 1) {
            return sprintf('%04d-%02d', $year, $month);
        }

        return null;
    }
}
