<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Rendering;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Dtos\AgentExecutionResult;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Presentation\DailyReportPresenter;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Presentation\OperationStatusPresenter;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Presentation\ProjectDetailPresenter;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Presentation\ProjectListPresenter;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Presentation\SiteInfoPresenter;

final class ContentProjectResultRenderer implements AgentResultRenderer
{
    public function supports(AgentExecutionResult $result): bool
    {
        return str_starts_with($result->capabilityKey, 'content_project.');
    }

    public function render(AgentExecutionResult $result): array
    {
        if (! $result->ok) {
            return [
                'title' => 'Không thực hiện được',
                'summary' => $this->userErrorMessage($result),
                'body' => $this->userErrorMessage($result),
                'user_facing' => true,
                'hide_envelope' => true,
                'badges' => [],
                'links' => [],
                'metrics' => [],
                'warnings' => [],
                'suggested_skills' => [],
                'operation_reference' => null,
                'details' => [],
            ];
        }

        return match ($result->capabilityKey) {
            'content_project.list_projects' => (new ProjectListPresenter)->present($result->data),
            'content_project.get_project' => (new ProjectDetailPresenter)->present(
                isset($result->data['project']) ? $result->data : ['project' => $result->data]
            ),
            'content_project.get_status' => (new ProjectDetailPresenter)->present($result->data),
            'content_project.get_site_health' => (new SiteInfoPresenter)->present($result->data),
            'content_project.get_daily_report' => (new DailyReportPresenter)->present($result->data),
            'content_project.get_operation' => (new OperationStatusPresenter)->present($result->data),
            default => $this->writeResultCard($result),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function writeResultCard(AgentExecutionResult $result): array
    {
        $summary = trim((string) $result->message);
        if ($summary === '' || strcasecmp($summary, 'Read successful.') === 0) {
            $summary = 'Hoàn tất.';
        }

        return [
            'title' => 'Content Project',
            'summary' => $summary,
            'body' => $summary,
            'user_facing' => true,
            'hide_envelope' => true,
            'badges' => [],
            'links' => [],
            'metrics' => [],
            'warnings' => $result->warnings,
            'suggested_skills' => [],
            'operation_reference' => null,
            'details' => [],
        ];
    }

    private function userErrorMessage(AgentExecutionResult $result): string
    {
        $message = trim((string) $result->message);
        if ($message === '') {
            return 'Bạn không có quyền thực hiện thao tác này.';
        }
        if (str_contains(strtolower($message), 'stack trace') || str_contains($message, 'SQLSTATE')) {
            return 'Không thực hiện được thao tác này.';
        }

        return $message;
    }
}
