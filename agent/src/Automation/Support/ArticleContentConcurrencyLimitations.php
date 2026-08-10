<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Support;

/**
 * Documented concurrency limitations — không đổi schema; chỉ tham chiếu cho Phase 4B.
 */
final class ArticleContentConcurrencyLimitations
{
    /**
     * @return list<array{id: string, detail: string}>
     */
    public static function catalog(): array
    {
        return [
            [
                'id' => 'no_revision_column',
                'detail' => 'articles không có revision/version column; expected_revision chưa hỗ trợ.',
            ],
            [
                'id' => 'hash_trim_only',
                'detail' => 'contentHash chỉ trim(); chưa normalize HTML entity, whitespace nội bộ, hay line endings (CRLF vs LF).',
            ],
            [
                'id' => 'updated_at_second_precision',
                'detail' => 'So sánh updated_at bằng getTimestamp() — mất microseconds; race trong cùng giây có thể lọt.',
            ],
            [
                'id' => 'timezone_parse',
                'detail' => 'expected_updated_at parse qua Carbon; caller phải gửi offset/ISO rõ ràng.',
            ],
            [
                'id' => 'optional_guards',
                'detail' => 'Thiếu cả expected_updated_at và expected_content_hash → không conflict check (backward compatible).',
            ],
            [
                'id' => 'updated_at_soft_when_hash_matches',
                'detail' => 'updated_at lệch nhưng expected_content_hash khớp body → cho qua (meta/touch lệch, không phải writer đổi nội dung).',
            ],
            [
                'id' => 'force_overwrite_higher_roles',
                'detail' => 'actualRole rank > content_manager (owner/admin/manager/planner) bỏ qua conflict via canForceArticleContentOverwrite.',
            ],
            [
                'id' => 'race_window',
                'detail' => 'Check ngoài lock rồi check lại trong lock; vẫn có TOCTOU hẹp giữa readers.',
            ],
        ];
    }
}
