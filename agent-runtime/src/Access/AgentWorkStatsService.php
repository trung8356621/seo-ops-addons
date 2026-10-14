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

        $window = WorkStatsWindow::fromMessage($message);
        $stats = $this->reader->summarize($context, $kind, $window);
        $limitation = is_string($stats['limitation'] ?? null) ? (string) $stats['limitation'] : '';
        if ($limitation !== '' && $this->isTeamQuestion($kind)) {
            return new AgentResponse($limitation, [['type' => 'markdown', 'text' => $limitation]], [], [[
                'name' => 'work.stats',
                'status' => 'limited',
                'request' => $kind,
            ]]);
        }

        $text = match ($kind) {
            'remaining' => 'Bạn còn '.(int) $stats['open_tasks'].' bài chưa hoàn thành'.$this->scopeSuffix($window).'.',
            'average' => 'SEO Score trung bình công việc của bạn'.$this->scopeSuffix($window).' là '.$this->scoreLabel($stats['average_seo_score'] ?? null).'.',
            'lowest_score' => $this->lowestScoreText($stats, $window),
            'lowest_output' => 'Năng suất thấp nhất'.$this->scopeSuffix($window).' trong phạm vi được phép: '.($stats['lowest_output_user'] ?? 'không có dữ liệu').'.'.$this->table($stats),
            'most_completed' => $this->mostCompletedText($stats, $window),
            'leaderboard' => $this->leaderboardIntro($window).$this->table($stats),
            default => $this->planText($stats, $window),
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
        if (preg_match('/xếp hạng|xep hang|bảng xếp hạng/u', $text) === 1) {
            return 'leaderboard';
        }
        if (preg_match('/hoàn thành nhiều|hoan thanh nhieu|nhiều bài nhất|nhieu bai nhat/u', $text) === 1) {
            return 'most_completed';
        }
        if (preg_match('/năng suất|productivity/u', $text) === 1) {
            return 'lowest_output';
        }
        if (preg_match('/ai đang|ai có|who has/u', $text) === 1 && preg_match('/seo score|điểm seo/u', $text) === 1) {
            return 'lowest_score';
        }
        if (preg_match('/trung bình|average/u', $text) === 1) {
            return 'average';
        }
        if (preg_match('/chưa hoàn thành|chưa xong|còn nợ|nợ mấy|còn bao nhiêu|remaining/u', $text) === 1) {
            return 'remaining';
        }

        return 'summary';
    }

    private function isTeamQuestion(string $kind): bool
    {
        return in_array($kind, ['lowest_score', 'lowest_output', 'leaderboard', 'most_completed'], true);
    }

    private function scopeSuffix(WorkStatsWindow $window): string
    {
        return $window->phrase === '' ? '' : ' '.$window->phrase;
    }

    private function scoreLabel(mixed $score): string
    {
        return is_numeric($score) ? (string) $score : 'Chưa có dữ liệu';
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function planText(array $stats, WorkStatsWindow $window): string
    {
        $body = (int) $stats['project_count'].' dự án, '.(int) $stats['open_tasks'].' bài chưa hoàn thành và '.(int) $stats['completed_tasks'].' bài đã hoàn thành.';
        if ($window->phrase === '') {
            return 'Bạn có '.$body;
        }

        return ucfirst($window->phrase).' bạn có '.$body;
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function lowestScoreText(array $stats, WorkStatsWindow $window): string
    {
        $name = $stats['lowest_score_user'] ?? null;
        $lead = is_string($name) && $name !== ''
            ? 'SEO Score thấp nhất'.$this->scopeSuffix($window).' trong team: '.$name.'.'
            : 'Chưa có dữ liệu SEO Score'.$this->scopeSuffix($window).' trong phạm vi team được phép.';

        return $lead.$this->table($stats);
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function mostCompletedText(array $stats, WorkStatsWindow $window): string
    {
        $name = $stats['most_completed_user'] ?? null;
        $lead = is_string($name) && $name !== ''
            ? 'Người hoàn thành nhiều bài nhất'.$this->scopeSuffix($window).': '.$name.'.'
            : 'Chưa có bài hoàn thành'.$this->scopeSuffix($window).' trong phạm vi team được phép.';

        return $lead.$this->table($stats);
    }

    private function leaderboardIntro(WorkStatsWindow $window): string
    {
        if ($window->phrase === '') {
            return 'Xếp hạng SEO Score các thành viên trong team:';
        }

        return 'Xếp hạng SEO Score các thành viên trong team '.$window->phrase.':';
    }

    /**
     * @param  array<string, mixed>  $stats
     */
    private function table(array $stats): string
    {
        $members = $stats['members'] ?? null;
        if (! is_array($members) || $members === []) {
            return '';
        }

        $lines = [
            '',
            '',
            '| Hạng | Thành viên | Bài được giao | Bài hoàn thành | SEO Score |',
            '| --- | --- | --- | --- | --- |',
        ];
        foreach ($members as $row) {
            if (! is_array($row)) {
                continue;
            }
            $rank = $row['rank'] ?? null;
            $lines[] = '| '.($rank === null ? '—' : (string) $rank)
                .' | '.(string) ($row['name'] ?? '')
                .' | '.(int) ($row['assigned'] ?? 0)
                .' | '.(int) ($row['completed'] ?? 0)
                .' | '.$this->scoreLabel($row['average_seo_score'] ?? null)
                .' |';
        }

        return implode("\n", $lines);
    }
}
