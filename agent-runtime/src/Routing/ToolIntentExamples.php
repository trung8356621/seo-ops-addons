<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Routing;

/**
 * Maintainable closed-set examples for namespace agent_tool_intents.
 * Keys stay aligned with AgentCapabilityCatalog; this file does not authorize execution.
 */
final class ToolIntentExamples
{
    /**
     * @return list<array{key: string, positive_examples: list<string>, negative_examples: list<string>}>
     */
    public static function all(): array
    {
        return [
            self::intent('seo_audit.worst_articles', [
                'tìm bài seo kém',
                'bài seo kém',
                'bài viết có điểm seo thấp',
                'bài seo điểm thấp',
                'bài nào cần tối ưu',
                'tìm bài điểm thấp',
                'nội dung nào đang hoạt động tệ',
                'worst seo articles',
                'cần sửa những bài',
            ], ['xuất bản bài viết', 'publish this article']),
            self::intent('gsc.performance', [
                'traffic tháng này',
                'impression giảm',
                'click gsc',
                'query tụt',
                'hiệu suất tìm kiếm',
                'search console performance',
            ]),
            self::intent('content_projects.read', [
                'draft hiện tại',
                'project tháng này',
                'kế hoạch nội dung',
                'bài đang chờ',
                'content project status',
            ]),
            self::intent('articles.inventory', [
                'danh sách bài viết',
                'kho bài trên site',
                'article inventory',
            ]),
            self::intent('links.internal', [
                'internal link nào đang có',
                'liên kết nội bộ',
                'internal links inventory',
            ]),
            self::intent('keywords.inventory', [
                'từ khóa đang theo dõi',
                'danh sách keyword đang quản lý',
                'managed keyword inventory',
            ]),
            self::intent('keywords.landscape', [
                'keyword landscape',
                'chủ đề có mức độ bao phủ thấp',
                'topic coverage',
            ]),
        ];
    }

    /**
     * @param  list<string>  $allowedKeys
     * @return list<array{key: string, positive_examples: list<string>, negative_examples: list<string>}>
     */
    public static function forKeys(array $allowedKeys): array
    {
        $allowed = array_fill_keys($allowedKeys, true);

        return array_values(array_filter(
            self::all(),
            static fn (array $intent): bool => isset($allowed[$intent['key']]),
        ));
    }

    /**
     * @param  list<string>  $positive
     * @param  list<string>  $negative
     * @return array{key: string, positive_examples: list<string>, negative_examples: list<string>}
     */
    private static function intent(string $key, array $positive, array $negative = []): array
    {
        return [
            'key' => $key,
            'positive_examples' => $positive,
            'negative_examples' => $negative,
        ];
    }
}
