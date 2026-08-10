<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace;

/**
 * Maps technical error codes to friendly Vietnamese messages.
 * Never exposes stack traces.
 */
final class AgentErrorPresentation
{
    /** @var array<string, string> */
    private const MAP = [
        'content_project.archive_blocked_running' => 'Project đang chạy AI nên chưa thể archive.',
        'serp_provider.not_configured' => 'Website chưa cấu hình nhà cung cấp SERP.',
        'keyword.analysis_already_processing' => 'Workspace này đang được phân tích.',
        'not_configured' => 'Skill chưa sẵn sàng vì thiếu cấu hình.',
        'permission_denied' => 'Bạn không có quyền thực hiện thao tác này.',
        'not_implemented' => 'Chức năng chưa được triển khai.',
        'coming_soon' => 'Chức năng đang phát triển.',
        'wrong_context' => 'Context hiện tại không phù hợp với skill này.',
        'quota_exceeded' => 'Đã vượt hạn mức sử dụng Agent Workspace.',
        'confirmation_required' => 'Cần xác nhận trước khi thực hiện.',
        'CAPABILITY_NOT_FOUND' => 'Capability không tồn tại.',
        'OPERATION_ALREADY_PROCESSING' => 'Đang có operation chạy — thử lại sau.',
        'TENANT_ACCESS_DENIED' => 'Không được phép truy cập tenant/site này.',
        'CONTEXT_MISSING' => 'Thiếu context site/tenant.',
        'INVALID_INPUT' => 'Dữ liệu nhập chưa hợp lệ.',
        'RESOURCE_NOT_FOUND' => 'Không tìm thấy tài nguyên.',
    ];

    public function present(string $code, string $fallback = ''): string
    {
        if (isset(self::MAP[$code])) {
            return self::MAP[$code];
        }

        $lower = mb_strtolower($code);
        if (isset(self::MAP[$lower])) {
            return self::MAP[$lower];
        }

        if (str_contains($lower, 'archive') && str_contains($lower, 'running')) {
            return self::MAP['content_project.archive_blocked_running'];
        }
        if (str_contains($lower, 'serp') && str_contains($lower, 'not_configured')) {
            return self::MAP['serp_provider.not_configured'];
        }
        if (str_contains($lower, 'already_processing') || str_contains($lower, 'analysis_already')) {
            return self::MAP['keyword.analysis_already_processing'];
        }

        $clean = trim($fallback);
        if ($clean !== '' && ! str_contains($clean, 'Stack trace') && ! str_contains($clean, 'vendor/')) {
            return $clean;
        }

        return 'Không thể hoàn thành yêu cầu. Xem Diagnostics để biết chi tiết kỹ thuật.';
    }
}
