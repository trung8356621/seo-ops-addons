<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Access;

use Omnichannel\Addons\AgentRuntime\Catalog\AgentCapabilityCatalog;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponse;

final class AgentWorkStatsService
{
    public function __construct(private readonly AgentWorkStatsReader $reader) {}

    public static function registerCapability(): void
    {
        if (AgentCapabilityCatalog::known('work.stats')) {
            return;
        }

        AgentCapabilityCatalog::register('work.stats', [
            'label' => 'Role-scoped work stats',
            'description' => 'Assigned-project or team statistics grounded in Laravel data.',
            'jev_selectable' => false,
            'execution_mode' => 'direct',
            'requires_confirmation' => false,
            'status' => 'available',
            'modules' => ['content_projects'],
        ]);
    }

    public function answer(AgentActorAccessContext $context, string $message): AgentResponse
    {
        $kind = $this->kind($message);
        if ($this->isTeamQuestion($kind) && ! $context->teamVisible) {
            $text = 'Bạn chỉ xem được công việc trên Content Project được giao. Câu hỏi về nhân sự khác nằm ngoài quyền của tài khoản này.';

            return new AgentResponse($text, [['type' => 'markdown', 'text' => $text]], [], []);
        }

        $stats = $this->reader->summarize($context, $kind);
        $text = match ($kind) {
            'remaining' => 'Bạn còn '.(int) $stats['open_tasks'].' bài chưa hoàn thành trong tháng này.',
            'average' => 'SEO Score trung bình công việc của bạn là '.($stats['average_seo_score'] === null ? 'chưa có số đo' : (string) $stats['average_seo_score']).'.',
            'lowest_score' => 'SEO Score thấp nhất trong phạm vi được phép: '.($stats['lowest_score_user'] ?? 'không có dữ liệu').'.',
            'lowest_output' => 'Năng suất thấp nhất tháng này trong phạm vi được phép: '.($stats['lowest_output_user'] ?? 'không có dữ liệu').'.',
            default => 'Tháng này bạn có '.(int) $stats['project_count'].' dự án, '.(int) $stats['open_tasks'].' bài chưa hoàn thành và '.(int) $stats['completed_tasks'].' bài đã hoàn thành.',
        };

        return new AgentResponse($text, [['type' => 'markdown', 'text' => $text]], [], [[
            'name' => 'work.stats',
            'status' => 'ok',
            'request' => $kind,
        ]]);
    }

    public function kind(string $message): string
    {
        $text = mb_strtolower($message);
        if (preg_match('/năng suất|productivity/u', $text) === 1) {
            return 'lowest_output';
        }
        if (preg_match('/ai đang|ai có|who has/u', $text) === 1 && preg_match('/seo score|điểm seo/u', $text) === 1) {
            return 'lowest_score';
        }
        if (preg_match('/trung bình|average/u', $text) === 1) {
            return 'average';
        }
        if (preg_match('/chưa hoàn thành|còn bao nhiêu|remaining/u', $text) === 1) {
            return 'remaining';
        }

        return 'month_plan';
    }

    private function isTeamQuestion(string $kind): bool
    {
        return $kind === 'lowest_score' || $kind === 'lowest_output';
    }
}
