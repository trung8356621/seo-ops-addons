<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Access;

use Omnichannel\Addons\AgentRuntime\Catalog\AgentCapabilityCatalog;
use Omnichannel\Addons\AgentRuntime\Response\AgentResponse;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;

final class AgentWorkHistoryService
{
    public function __construct(private readonly AgentWorkHistoryReader $reader) {}

    public static function registerCapability(): void
    {
        if (AgentCapabilityCatalog::known('work.history')) {
            return;
        }

        AgentCapabilityCatalog::register('work.history', [
            'label' => 'Assigned work history',
            'description' => 'Read-only paginated history of assigned Content Project tasks.',
            'jev_selectable' => false,
            'execution_mode' => 'direct',
            'requires_confirmation' => false,
            'status' => 'available',
            'modules' => ['content_projects'],
        ]);
    }

    public function answer(AgentActorAccessContext $context, string $message): AgentResponse
    {
        $page = WorkHistoryRecord::pageNumber($message);
        $history = $this->reader->inspect($context, $page, WorkHistoryRecord::PER_PAGE);
        $text = $this->summary($history);
        $blocks = [['type' => 'markdown', 'text' => $text]];
        $rows = $this->tableRows(is_array($history['rows'] ?? null) ? $history['rows'] : []);
        if ($rows !== []) {
            $blocks[] = [
                'type' => 'table',
                'title' => 'Lịch sử công việc',
                'columns' => [
                    ['key' => 'project', 'label' => 'Content Project'],
                    ['key' => 'month', 'label' => 'Tháng'],
                    ['key' => 'task_id', 'label' => 'Task'],
                    ['key' => 'article_id', 'label' => 'Article'],
                    ['key' => 'type', 'label' => 'Loại'],
                    ['key' => 'status', 'label' => 'Trạng thái'],
                    ['key' => 'classification', 'label' => 'Phân loại'],
                    ['key' => 'outstanding', 'label' => 'Đang outstanding'],
                ],
                'rows' => $rows,
            ];
        }

        return new AgentResponse($text, $blocks, [], [[
            'name' => 'work.history',
            'status' => 'ok',
            'request' => 'history',
            'page' => (int) ($history['page'] ?? $page),
        ]]);
    }

    /**
     * @param  array<string, mixed>  $history
     */
    private function summary(array $history): string
    {
        $total = (int) ($history['total'] ?? 0);
        $page = (int) ($history['page'] ?? 1);
        $perPage = max(1, (int) ($history['per_page'] ?? WorkHistoryRecord::PER_PAGE));
        $pages = max(1, (int) ceil($total / $perPage));
        $shown = count(is_array($history['rows'] ?? null) ? $history['rows'] : []);

        return 'Lịch sử công việc của bạn, gồm cả dự án đã lưu trữ. '
            .'Kế hoạch: '.(int) ($history['planned'] ?? 0)
            .'. Đã vào quy trình Content Manager: '.(int) ($history['entered_workflow'] ?? 0)
            .'. Đã hoàn thành: '.(int) ($history['completed'] ?? 0)
            .'. Đang outstanding: '.(int) ($history['outstanding'] ?? 0)
            .'. Lưu trữ: '.(int) ($history['archived'] ?? 0)
            .'. Trang '.$page.'/'.$pages.', hiển thị '.$shown.'/'.$total.' task.';
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function tableRows(array $rows): array
    {
        $labels = [
            'archived' => 'Lưu trữ',
            'cancelled' => 'Đã hủy',
            'outstanding' => 'Cần biên tập',
            'completed' => 'Đã hoàn thành',
            'in_workflow' => 'Đang trong quy trình',
            'planned' => 'Kế hoạch chưa generate',
        ];
        $statuses = SeoProjectTask::statusOptions() + [
            SeoProjectTask::STATUS_DRAFT => 'Nháp',
            SeoProjectTask::STATUS_PROCESSING => 'Đang xử lý',
            SeoProjectTask::STATUS_ARCHIVED => 'Đã lưu trữ',
            SeoProjectTask::STATUS_CANCELLED => 'Đã hủy',
        ];
        $table = [];
        foreach ($rows as $row) {
            $projectId = (int) ($row['project_id'] ?? 0);
            $name = (string) ($row['project_name'] ?? '');
            $href = ContentProjectAgentLink::url($projectId);
            $articleId = (int) ($row['article_id'] ?? 0);
            $classification = (string) ($row['classification'] ?? '');
            $status = (string) ($row['status'] ?? '');
            $table[] = [
                'project' => $href !== null ? ['label' => $name, 'href' => $href] : $name,
                'month' => (string) ($row['month'] ?? ''),
                'task_id' => (int) ($row['task_id'] ?? 0),
                'article_id' => $articleId > 0 ? $articleId : '',
                'type' => (string) ($row['type'] ?? ''),
                'status' => $statuses[$status] ?? $status,
                'classification' => $labels[$classification] ?? $classification,
                'outstanding' => ! empty($row['outstanding']) ? 'Có' : 'Không',
            ];
        }

        return $table;
    }
}
