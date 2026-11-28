<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\StructuredRag;

final class QueryUnderstandingService
{
    /** @param list<array{role: string, content: string}> $history */
    public function understand(string $message, array $history = []): QueryUnderstanding
    {
        $text = mb_strtolower(trim($message));
        $contextText = $this->contextText($history);
        $usesHistory = $contextText !== '' && preg_match('/(?:vậy|nhóm nào|trước|ở trên|đó|này|vừa nói)/iu', $text) === 1;
        $effective = trim(($usesHistory ? $contextText.' ' : '').$text);
        $gscExcluded = preg_match('/(?:gsc|search console|clicks|impressions|ctr|vị trí trung bình)/iu', $effective) === 1;

        $siteObject = preg_match('/(?:website|site|trang|bài|article|nội dung|từ khóa|keyword|topic|chủ đề|kho|dữ liệu|audit|crawl|url|tài sản seo)/iu', $effective) === 1;
        $siteAction = preg_match('/(?<!\p{L})(?:đang|hiện có|có mặt|thuộc|gắn|liên kết|liệt kê|xuất|bao nhiêu|kiểm tra|cho tôi xem|tìm|mạnh nhất|ưu tiên|đánh giá|tình trạng)(?!\p{L})/iu', $effective) === 1;
        $definition = preg_match('/(?:là gì|nghĩa là gì|what is|giải thích|khác .{0,20} chỗ nào)/iu', $text) === 1;
        $isGeneralKnowledge = $definition && ! ($siteObject && $siteAction);

        $requirements = [];
        $entities = [];
        $parameters = [];
        $articleIntent = preg_match('/(?:bài viết|bài cũ|article|nội dung hiện có|kho nội dung|url|tài sản seo)/iu', $effective) === 1;
        $articleQuality = preg_match('/(?:seo score|điểm seo|audit|on.?page|tối ưu bài|chất lượng|lỗi|điểm mạnh|điểm yếu|mạnh nhất|tài sản seo)/iu', $effective) === 1;
        $keywordIntent = preg_match('/(?:từ khóa|keyword|cụm từ khóa|truy vấn)/iu', $effective) === 1;
        $topicIntent = preg_match('/(?:topic|chủ đề|bao phủ|coverage|nội dung mới|phát triển nội dung|dna)/iu', $effective) === 1;
        $technicalIntent = preg_match('/(?:canonical|redirect|http status|trang lỗi|audit kỹ thuật|technical audit|crawl)/iu', $effective) === 1;

        if ($articleIntent) $requirements[] = 'articles';
        if ($articleQuality) $requirements[] = 'article_quality';
        if ($keywordIntent && preg_match('/(?:đang theo dõi|kho|danh sách|liệt kê|có mặt|keyword:)/iu', $effective) === 1) $requirements[] = 'keyword_inventory';
        if ($keywordIntent && ! in_array('keyword_inventory', $requirements, true)) $requirements[] = 'keyword_landscape';
        if ($topicIntent) $requirements[] = 'topic_coverage';
        $this->addWhen($requirements, 'internal_links', '/(?:internal link|liên kết nội bộ)/iu', $effective);
        $this->addWhen($requirements, 'external_links', '/(?:external link|liên kết ngoài|backlink)/iu', $effective);
        $this->addWhen($requirements, 'content_projects', '/(?:content project|dự án nội dung|kế hoạch nội dung|draft)/iu', $effective);
        $this->addWhen($requirements, 'site_knowledge', '/(?:tổng quan|thông tin website|website là gì)/iu', $effective);
        if ($technicalIntent) {
            $requirements = array_values(array_filter($requirements, static fn (string $value): bool => ! in_array($value, ['article_quality', 'articles'], true)));
            $requirements[] = 'technical_audit';
        }

        foreach (['article', 'topic', 'keyword'] as $type) {
            if (preg_match('/\b'.$type.':[1-9]\d*\b/i', $effective, $match) === 1) {
                $ref = strtolower($match[0]);
                $parameters[$type.'_ref'] = $ref;
                $parameters['entity_type'] = $type;
                $entities[] = $ref;
            }
        }

        $isDatasetRequest = preg_match('/(?:liệt kê|xuất|danh sách).{0,30}\d{1,4}/iu', $text) === 1;
        $query = $this->quotedEntity($message);
        if ($query === null && ! $isDatasetRequest && preg_match('/(?:của bài|chi tiết bài)\s+/iu', $message) === 1) {
            $query = $this->namedEntity($message, 'article');
        }
        if ($query !== null && $entities === []) {
            $parameters['entity_type'] = $topicIntent ? 'topic' : ($keywordIntent ? 'keyword' : 'article');
            $parameters['entity_query'] = $query;
        }

        if (preg_match('/(?:tối đa|top|lấy|liệt kê|xuất)\s*(\d{1,4})/iu', $text, $match) === 1) {
            $parameters['limit_max'] = max(1, min(250, (int) $match[1]));
        }
        if (preg_match('/(?:đã xuất bản|published)/iu', $effective) === 1) $parameters['status'] = 'published';
        if (preg_match('/(?:chỉ cần|mỗi bài chỉ cần).{0,60}(?:tiêu đề|title).{0,40}(?:url|mã bài|article id)/iu', $effective) === 1) $parameters['projection'] = 'article_identity';
        if (($parameters['limit_max'] ?? 0) > 50 && in_array('articles', $requirements, true)) $parameters['dataset_export'] = true;

        $decisionLanguage = preg_match('/(?:nên|ưu tiên|so sánh|vì sao|nguyên nhân|cải thiện|tối ưu|phân tích|đề xuất|cơ hội|chiến lược|mạnh nhất|chọn|quyết định|còn thiếu)/iu', $effective) === 1;
        $requiresAnalysis = ! $isGeneralKnowledge && $decisionLanguage;
        $requiresRetrieval = ! $isGeneralKnowledge && $requirements !== [];
        if ($gscExcluded && array_diff($requirements, ['site_knowledge']) === []) {
            $requirements = [];
            $requiresRetrieval = false;
        }

        $scope = $parameters['article_ref'] ?? $parameters['topic_ref'] ?? $parameters['keyword_ref'] ?? ($parameters['entity_type'] ?? null) ?? ($siteObject ? 'site' : 'module');
        $entityAware = isset($parameters['entity_type']);
        $relationshipRequired = $entityAware && ($topicIntent || $keywordIntent) && ($articleIntent || preg_match('/(?:gắn|liên kết|thuộc)/iu', $effective) === 1);
        $depth = ($requiresAnalysis || $entityAware || count($requirements) > 1) ? 'evidence' : 'summary';
        $limitations = $gscExcluded ? ['gsc_excluded'] : [];

        return new QueryUnderstanding(
            $isGeneralKnowledge ? 'general_knowledge' : ($requiresAnalysis ? 'analysis' : ($entityAware ? 'entity_lookup' : (($parameters['dataset_export'] ?? false) ? 'dataset' : 'factual'))),
            $requiresRetrieval,
            $requiresAnalysis,
            (string) $scope,
            $depth,
            array_values(array_unique($requirements)),
            array_values(array_unique($entities)),
            $parameters,
            [
                'history_messages_used' => $usesHistory ? min(6, count($history)) : 0,
                'history_subject_applied' => $usesHistory,
                'entity_aware' => $entityAware,
                'relationship_required' => $relationshipRequired,
                'direct_response_eligible' => ! $requiresAnalysis && ! $relationshipRequired && ! $entityAware && ! ($parameters['dataset_export'] ?? false),
            ],
            $limitations,
        );
    }

    /** @param list<string> $requirements */
    private function addWhen(array &$requirements, string $requirement, string $pattern, string $text): void
    {
        if (preg_match($pattern, $text) === 1) $requirements[] = $requirement;
    }

    private function quotedEntity(string $message): ?string
    {
        if (preg_match('/[“"]([^”"]{2,160})[”"]/u', $message, $match) !== 1) return null;
        $value = trim($match[1]);
        return $value === '' ? null : $value;
    }

    private function namedEntity(string $message, ?string $type): ?string
    {
        $prefix = match ($type) {
            'topic' => '(?:topic|chủ đề)',
            'keyword' => '(?:keyword|từ khóa)',
            'article' => '(?:article|bài viết|bài)',
            default => null,
        };
        if ($prefix === null || preg_match('/'.$prefix.'\s+([^,.?!()]{3,160})/iu', $message, $match) !== 1) return null;
        $value = trim(preg_replace('/\s+(?:có|đang|thuộc|trong|và|tôi muốn).*$/iu', '', $match[1]) ?? $match[1]);
        return $value === '' ? null : $value;
    }

    /** @param list<array{role: string, content: string}> $history */
    private function contextText(array $history): string
    {
        $parts = [];
        foreach (array_slice($history, -6) as $item) {
            if (! is_array($item) || ! in_array(($item['role'] ?? ''), ['user', 'assistant'], true)) continue;
            $content = trim((string) ($item['content'] ?? ''));
            if ($content !== '') $parts[] = mb_substr($content, 0, 500);
        }
        return mb_strtolower(implode(' ', $parts));
    }
}
