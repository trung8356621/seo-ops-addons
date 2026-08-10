<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\GscIntelligence;

use Omnichannel\Addons\SearchIntelligence\Enums\Gsc\GscContentAction;

/**
 * Build Content Project preview item từ GSC evidence.
 * improve_description kiểu tiếng Việt — KHÔNG dùng gallery_description.
 */
final class GscContentProjectPreviewBuilder
{
    public const ALGORITHM_VERSION = '1.0.0';

    /**
     * @param  array<string, mixed>  $recommendation  action, reason_codes, article_ref
     * @param  array<string, mixed>  $metrics  clicks, impressions, ctr, position
     * @param  array<string, mixed>  $context  query, keyword_ref?, opportunities?
     * @return array<string, mixed>
     */
    public function build(array $recommendation, array $metrics, array $context = []): array
    {
        $action = $recommendation['action'] ?? GscContentAction::NeedsReview;
        $actionValue = $action instanceof GscContentAction ? $action->value : (string) $action;

        $query = (string) ($context['display_query'] ?? $context['query'] ?? '');
        $item = [
            'source' => 'gsc_intelligence',
            'action' => $actionValue,
            'reason_codes' => $recommendation['reason_codes'] ?? [],
            'article_ref' => $recommendation['article_ref'] ?? null,
            'keyword_ref' => $context['keyword_ref'] ?? null,
            'focus_keyword' => $query,
            'gsc_metrics' => [
                'clicks' => (int) ($metrics['clicks'] ?? 0),
                'impressions' => (int) ($metrics['impressions'] ?? 0),
                'ctr' => $metrics['ctr'] ?? null,
                'position' => $metrics['position'] ?? null,
            ],
            'gsc_opportunities' => $context['opportunities'] ?? [],
            'algorithm_version' => self::ALGORITHM_VERSION,
        ];

        if ($actionValue === GscContentAction::Improve->value) {
            $item['improve_description'] = $this->buildImproveDescription($query, $metrics, $context);
        }

        if ($actionValue === GscContentAction::Rewrite->value) {
            $item['rewrite_brief'] = $this->buildRewriteBrief($query, $metrics, $context);
        }

        // Explicit: never populate gallery_description from GSC path.
        unset($item['gallery_description']);

        return $item;
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $context
     */
    private function buildImproveDescription(string $query, array $metrics, array $context): string
    {
        $impressions = (int) ($metrics['impressions'] ?? 0);
        $ctr = $metrics['ctr'] ?? null;
        $position = $metrics['position'] ?? null;

        $parts = [];
        $parts[] = "Từ khóa «{$query}» đang có {$impressions} lượt hiển thị trên Google Search Console.";

        if ($ctr !== null) {
            $ctrPct = round(((float) $ctr) * 100, 2);
            $parts[] = "CTR hiện tại {$ctrPct}% — cần tối ưu title/meta và đoạn mở bài để tăng click.";
        }

        if ($position !== null) {
            $parts[] = 'Vị trí trung bình '.round((float) $position, 1).': bổ sung nội dung trả lời đúng intent, cải thiện internal link tới trang này.';
        }

        $opportunityHint = $this->firstOpportunityHint($context);
        if ($opportunityHint !== '') {
            $parts[] = $opportunityHint;
        }

        return implode(' ', $parts);
    }

    /**
     * @param  array<string, mixed>  $metrics
     * @param  array<string, mixed>  $context
     */
    private function buildRewriteBrief(string $query, array $metrics, array $context): string
    {
        $clicks = (int) ($metrics['clicks'] ?? 0);

        return "Trang đang mất dần traffic cho «{$query}» (clicks GSC: {$clicks}). "
            .'Đề xuất rewrite toàn diện: cập nhật heading, FAQ, ví dụ thực tế và CTA phù hợp intent hiện tại.';
    }

    /**
     * @param  array<string, mixed>  $context
     */
    private function firstOpportunityHint(array $context): string
    {
        $opportunities = is_array($context['opportunities'] ?? null) ? $context['opportunities'] : [];
        $first = $opportunities[0] ?? null;
        if (! is_array($first)) {
            return '';
        }

        return match ((string) ($first['type'] ?? '')) {
            'near_page_one' => 'Cơ hội đẩy lên trang 1: tăng độ sâu nội dung và schema phù hợp.',
            'high_impression_low_ctr' => 'Impression cao nhưng CTR thấp — thử A/B title và meta description.',
            default => '',
        };
    }
}
