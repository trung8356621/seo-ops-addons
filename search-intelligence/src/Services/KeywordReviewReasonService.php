<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services;

use Omnichannel\Addons\SearchIntelligence\Enums\KeywordReviewStatus;
use Omnichannel\Addons\SearchIntelligence\Models\KeywordReviewReason;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Illuminate\Support\Collection;
use InvalidArgumentException;
use RuntimeException;

final class KeywordReviewReasonService
{
    /**
     * @return Collection<int, KeywordReviewReason>
     */
    public function activeReasonsForWorkspace(?int $workspaceId = null): Collection
    {
        $workspaceId ??= SeoAccessControl::accountSiteOwnerId();

        return KeywordReviewReason::query()
            ->where('workspace_id', $workspaceId)
            ->where('is_active', true)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    /**
     * @return Collection<int, KeywordReviewReason>
     */
    public function allReasonsForWorkspace(?int $workspaceId = null): Collection
    {
        $workspaceId ??= SeoAccessControl::accountSiteOwnerId();

        return KeywordReviewReason::query()
            ->where('workspace_id', $workspaceId)
            ->orderBy('sort_order')
            ->orderBy('id')
            ->get();
    }

    public function ensureDefaultReasons(?int $workspaceId = null, ?int $createdBy = null): void
    {
        $workspaceId ??= SeoAccessControl::accountSiteOwnerId();
        $createdBy ??= (int) (auth()->id() ?? 0);

        if (KeywordReviewReason::query()->where('workspace_id', $workspaceId)->exists()) {
            return;
        }

        $sort = 0;
        foreach ($this->defaultReasonDefinitions() as $definition) {
            KeywordReviewReason::query()->create([
                'workspace_id' => $workspaceId,
                'name' => $definition['name'],
                'default_severity' => $definition['default_severity'],
                'description' => $definition['description'] ?? null,
                'is_active' => true,
                'sort_order' => $sort++,
                'created_by' => $createdBy > 0 ? $createdBy : null,
            ]);
        }
    }

    /**
     * @param  array{name: string, default_severity: string, description?: string|null, is_active?: bool, sort_order?: int}  $payload
     */
    public function createReason(array $payload, ?int $workspaceId = null, ?int $createdBy = null): KeywordReviewReason
    {
        $workspaceId ??= SeoAccessControl::accountSiteOwnerId();
        $createdBy ??= (int) (auth()->id() ?? 0);
        $severity = $this->normalizeSeverity((string) ($payload['default_severity'] ?? ''));

        return KeywordReviewReason::query()->create([
            'workspace_id' => $workspaceId,
            'name' => trim((string) ($payload['name'] ?? '')),
            'default_severity' => $severity->value,
            'description' => $this->nullableTrim($payload['description'] ?? null),
            'is_active' => (bool) ($payload['is_active'] ?? true),
            'sort_order' => max(0, (int) ($payload['sort_order'] ?? 0)),
            'created_by' => $createdBy > 0 ? $createdBy : null,
        ]);
    }

    /**
     * @param  array{name?: string, default_severity?: string, description?: string|null, is_active?: bool, sort_order?: int}  $payload
     */
    public function updateReason(KeywordReviewReason $reason, array $payload): KeywordReviewReason
    {
        $this->assertWorkspaceAccess((int) $reason->workspace_id);

        if (array_key_exists('name', $payload)) {
            $reason->name = trim((string) $payload['name']);
        }

        if (array_key_exists('default_severity', $payload)) {
            $reason->default_severity = $this->normalizeSeverity((string) $payload['default_severity'])->value;
        }

        if (array_key_exists('description', $payload)) {
            $reason->description = $this->nullableTrim($payload['description']);
        }

        if (array_key_exists('is_active', $payload)) {
            $reason->is_active = (bool) $payload['is_active'];
        }

        if (array_key_exists('sort_order', $payload)) {
            $reason->sort_order = max(0, (int) $payload['sort_order']);
        }

        $reason->save();

        return $reason->fresh() ?? $reason;
    }

    public function deleteReason(KeywordReviewReason $reason): void
    {
        $this->assertWorkspaceAccess((int) $reason->workspace_id);

        if ($reason->isUsed()) {
            throw new RuntimeException(__('seo-content-ai::filament.keyword_review.reason_in_use'));
        }

        $reason->delete();
    }

    public function findAccessibleReason(int $reasonId, ?int $workspaceId = null): ?KeywordReviewReason
    {
        $workspaceId ??= SeoAccessControl::accountSiteOwnerId();
        if ($reasonId <= 0) {
            return null;
        }

        return KeywordReviewReason::query()
            ->where('workspace_id', $workspaceId)
            ->whereKey($reasonId)
            ->first();
    }

    /**
     * @return list<array{name: string, default_severity: string, description?: string|null}>
     */
    private function defaultReasonDefinitions(): array
    {
        return [
            ['name' => 'Từ khóa quá dài', 'default_severity' => KeywordReviewStatus::Warning->value],
            ['name' => 'Từ khóa quá chung chung', 'default_severity' => KeywordReviewStatus::Warning->value],
            ['name' => 'Cách diễn đạt chưa tốt', 'default_severity' => KeywordReviewStatus::Warning->value],
            ['name' => 'Gần trùng với từ khóa khác', 'default_severity' => KeywordReviewStatus::Warning->value],
            ['name' => 'Chưa phù hợp bài viết hiện tại', 'default_severity' => KeywordReviewStatus::Warning->value],
            ['name' => 'Cần chỉnh lại trước khi sử dụng', 'default_severity' => KeywordReviewStatus::Warning->value],
            ['name' => 'Sai ý định tìm kiếm', 'default_severity' => KeywordReviewStatus::Danger->value],
            ['name' => 'Không liên quan sản phẩm hoặc dịch vụ', 'default_severity' => KeywordReviewStatus::Danger->value],
            ['name' => 'Từ khóa rác', 'default_severity' => KeywordReviewStatus::Danger->value],
            ['name' => 'Dữ liệu từ khóa sai', 'default_severity' => KeywordReviewStatus::Danger->value],
            ['name' => 'Không có giá trị chuyển đổi', 'default_severity' => KeywordReviewStatus::Danger->value],
            ['name' => 'Không phù hợp định hướng nội dung', 'default_severity' => KeywordReviewStatus::Danger->value],
            ['name' => 'Có nguy cơ làm sai lệch nội dung', 'default_severity' => KeywordReviewStatus::Danger->value],
        ];
    }

    private function normalizeSeverity(string $severity): KeywordReviewStatus
    {
        $severity = trim($severity);
        $enum = KeywordReviewStatus::tryFrom($severity);
        if ($enum === null || $enum === KeywordReviewStatus::Active) {
            throw new InvalidArgumentException(__('seo-content-ai::filament.keyword_review.invalid_severity'));
        }

        return $enum;
    }

    private function nullableTrim(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function assertWorkspaceAccess(int $workspaceId): void
    {
        if ($workspaceId !== SeoAccessControl::accountSiteOwnerId()) {
            throw new RuntimeException(__('seo-content-ai::filament.keyword_review.reason_access_denied'));
        }
    }
}
