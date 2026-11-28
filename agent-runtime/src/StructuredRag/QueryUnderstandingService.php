<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\StructuredRag;

final class QueryUnderstandingService
{
    /**
     * @param list<array{role: string, content: string}> $history
     */
    public function understand(string $message, array $history = []): QueryUnderstanding
    {
        $text = mb_strtolower(trim($message));
        $contextText = $this->contextText($history);
        $effective = trim($contextText.' '.$text);
        $gscExcluded = preg_match('/(?:gsc|search console|clicks|impressions|ctr|vị trí trung bình)/iu', $effective) === 1;

        $isGeneralKnowledge = preg_match('/(?:là gì|nghĩa là gì|what is|giải thích khái niệm)/iu', $text) === 1
            && preg_match('/(?:website|trang|bài|từ khóa|keyword|topic|dữ liệu|hiện có|đang)/iu', $text) !== 1;

        $requirements = [];
        $entities = [];
        $parameters = [];

        $this->addWhen($requirements, 'articles', '/(?:bài viết|bài cũ|nội dung|article|content)/iu', $effective);
        $this->addWhen($requirements, 'article_quality', '/(?:seo score|điểm seo|audit|tối ưu bài|chất lượng bài|vấn đề on.?page)/iu', $effective);
        $this->addWhen($requirements, 'keyword_inventory', '/(?:đang theo dõi.*từ khóa|kho từ khóa|keyword inventory|danh sách từ khóa)/iu', $effective);
        $this->addWhen($requirements, 'keyword_landscape', '/(?:từ khóa|keyword|cơ hội tìm kiếm|search intent)/iu', $effective);
        $this->addWhen($requirements, 'topic_coverage', '/(?:topic|chủ đề|bao phủ|coverage|nội dung mới|phát triển nội dung)/iu', $effective);
        $this->addWhen($requirements, 'internal_links', '/(?:internal link|liên kết nội bộ)/iu', $effective);
        $this->addWhen($requirements, 'external_links', '/(?:external link|liên kết ngoài|backlink)/iu', $effective);
        $this->addWhen($requirements, 'content_projects', '/(?:content project|dự án nội dung|kế hoạch nội dung|draft)/iu', $effective);
        $this->addWhen($requirements, 'site_knowledge', '/(?:toàn site|toàn website|cả website|website này|site này|tổng quan)/iu', $effective);

        if ($gscExcluded && array_diff($requirements, ['site_knowledge']) === []) {
            $requirements = [];
        }

        if (preg_match('/\barticle:[1-9]\d*\b/i', $effective, $match) === 1) {
            $parameters['article_ref'] = strtolower($match[0]);
            $entities[] = $parameters['article_ref'];
        }
        if (preg_match('/\btopic:[1-9]\d*\b/i', $effective, $match) === 1) {
            $parameters['topic_ref'] = strtolower($match[0]);
            $entities[] = $parameters['topic_ref'];
        }
        if (preg_match('/(?:tối đa|top|lấy|liệt kê)\s*(\d{1,3})/iu', $text, $match) === 1) {
            $parameters['limit_max'] = max(1, min(100, (int) $match[1]));
        }

        $requiresAnalysis = preg_match('/(?:nên|ưu tiên|so sánh|vì sao|nguyên nhân|cải thiện|tối ưu|phân tích|đề xuất|cơ hội|chiến lược|mạnh nhất|nút thắt)/iu', $effective) === 1;
        $requiresRetrieval = ! $isGeneralKnowledge && $requirements !== [];
        $scope = $parameters['article_ref'] ?? $parameters['topic_ref'] ?? (preg_match('/(?:toàn site|toàn website|cả website|website này|site này|tổng quan)/iu', $effective) === 1 ? 'site' : 'module');
        $depth = $requiresAnalysis && count($requirements) > 1 ? 'evidence' : 'summary';

        $limitations = [];
        if ($gscExcluded) {
            $limitations[] = 'gsc_excluded';
        }

        return new QueryUnderstanding(
            $isGeneralKnowledge ? 'general_knowledge' : ($requiresAnalysis ? 'analysis' : 'factual'),
            $requiresRetrieval,
            $requiresAnalysis,
            (string) $scope,
            $depth,
            array_values(array_unique($requirements)),
            array_values(array_unique($entities)),
            $parameters,
            [
                'history_messages_used' => min(6, count($history)),
                'history_subject_applied' => $contextText !== '' && preg_match('/(?:vậy|nhóm nào|trước|ở trên|đó|này)/iu', $text) === 1,
            ],
            $limitations,
        );
    }

    /** @param list<string> $requirements */
    private function addWhen(array &$requirements, string $requirement, string $pattern, string $text): void
    {
        if (preg_match($pattern, $text) === 1) {
            $requirements[] = $requirement;
        }
    }

    /** @param list<array{role: string, content: string}> $history */
    private function contextText(array $history): string
    {
        $parts = [];
        foreach (array_slice($history, -6) as $item) {
            if (! is_array($item) || ! in_array(($item['role'] ?? ''), ['user', 'assistant'], true)) {
                continue;
            }
            $content = trim((string) ($item['content'] ?? ''));
            if ($content !== '') {
                $parts[] = mb_substr($content, 0, 500);
            }
        }

        return mb_strtolower(implode(' ', $parts));
    }
}
