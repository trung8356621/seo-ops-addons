<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services;

use Omnichannel\Addons\Seo\Contracts\SeoProjectWorkflowStepCatalogContract;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\ContentProjects\Enums\WorkflowExecutionRole;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Models\SeoTask;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectStepSourceValidator;
use Omnichannel\Addons\ContentProjects\Services\WorkflowRoles\WorkflowExecutionRoleResolver;
use Omnichannel\Addons\ContentProjects\Support\ContentProject\ContentProjectStepDescriptor;
use Omnichannel\Addons\Media\Support\ImageToolType;

/**
 * Danh sách bước prompt có thể «Chạy lại» từ workflow SeoTask của project.
 * Identity: node_id + execution_role / hook_key — không title heuristic.
 */
final class SeoProjectWorkflowStepCatalogService implements SeoProjectWorkflowStepCatalogContract
{
    public function __construct(
        private readonly SeoCreateArticleSettingsService $settings,
        private readonly WorkflowExecutionRoleResolver $roleResolver,
        private readonly ContentProjectStepSourceValidator $sourceValidator,
    ) {}

    /**
     * @return list<ContentProjectStepDescriptor>
     */
    public function listStepDescriptors(SeoProjectTask $projectTask, bool $rerunnableOnly = true): array
    {
        $seoTask = $this->resolveSeoTaskForStepRetry($projectTask);
        if (! $seoTask instanceof SeoTask) {
            return [];
        }

        $flow = is_array($seoTask->flow_data) ? $seoTask->flow_data : [];
        $nodes = is_array($flow['nodes'] ?? null) ? $flow['nodes'] : [];
        $edges = is_array($flow['edges'] ?? null) ? $flow['edges'] : [];
        $adjacency = $this->buildAdjacency($edges);

        $promptNodes = [];
        foreach ($nodes as $index => $node) {
            if (! is_array($node)) {
                continue;
            }
            if ((string) ($node['type'] ?? '') !== 'prompt') {
                continue;
            }
            $nodeId = trim((string) ($node['id'] ?? ''));
            if ($nodeId === '') {
                continue;
            }
            $promptNodes[] = ['index' => (int) $index, 'node' => $node, 'node_id' => $nodeId];
        }

        $descriptors = [];
        $sequence = 0;
        foreach ($promptNodes as $entry) {
            $node = $entry['node'];
            $nodeId = $entry['node_id'];
            $sequence++;

            $title = trim((string) ($node['title'] ?? ''));
            if ($title === '') {
                $title = 'Prompt';
            }

            $promptId = isset($node['data']['promptId']) ? (int) $node['data']['promptId'] : null;
            $prompt = $promptId !== null && $promptId > 0
                ? SeoPrompt::query()->find($promptId)
                : null;

            $role = $this->roleResolver->readRole($node);
            $hookKey = $this->resolveHookKey($node, $prompt);
            $postType = $this->resolvePostType($node, $prompt);
            $kind = $this->detectKind($node, $prompt, $hookKey);
            $label = $this->labelForDescriptor($kind, $hookKey, $postType, $role, $title);
            $rerunnable = $this->isRerunnablePromptNode($node, $prompt, $kind, $hookKey);
            $unavailable = $rerunnable ? null : 'Node không rerunnable (thiếu Prompt / internal).';

            $descriptor = new ContentProjectStepDescriptor(
                nodeId: $nodeId,
                executionRole: $role?->value,
                postType: $postType,
                hookKey: $hookKey,
                label: $label,
                kind: $kind,
                sequence: $sequence,
                rerunnable: $rerunnable,
                sourceRequirements: [],
                downstreamNodeIds: $this->downstreamPromptNodeIds($nodeId, $adjacency, $promptNodes),
                promptId: $prompt instanceof SeoPrompt ? (int) $prompt->id : ($promptId > 0 ? $promptId : null),
                title: $title,
                unavailableReason: $unavailable,
            );

            // Fill source requirements from validator defaults (role/kind map).
            $requirements = $this->sourceValidator->defaultRequirements($descriptor);
            $descriptor = new ContentProjectStepDescriptor(
                nodeId: $descriptor->nodeId,
                executionRole: $descriptor->executionRole,
                postType: $descriptor->postType,
                hookKey: $descriptor->hookKey,
                label: $descriptor->label,
                kind: $descriptor->kind,
                sequence: $descriptor->sequence,
                rerunnable: $descriptor->rerunnable,
                sourceRequirements: $requirements,
                downstreamNodeIds: $descriptor->downstreamNodeIds,
                promptId: $descriptor->promptId,
                title: $descriptor->title,
                unavailableReason: $descriptor->unavailableReason,
            );

            if ($rerunnableOnly && ! $descriptor->rerunnable) {
                continue;
            }

            $descriptors[] = $descriptor;
        }

        return $descriptors;
    }

    /**
     * @return list<array{
     *     node_id: string,
     *     title: string,
     *     label: string,
     *     kind: string,
     *     execution_role: ?string,
     *     prompt_id: int|null,
     *     depends_on_kinds: list<string>,
     *     hook_key: ?string,
     *     post_type: ?string,
     *     sequence: int,
     *     rerunnable: bool,
     *     source_requirements: list<string>,
     *     downstream_nodes: list<string>
     * }>
     */
    public function listRerunnableSteps(SeoProjectTask $projectTask): array
    {
        $steps = [];
        foreach ($this->listStepDescriptors($projectTask, true) as $descriptor) {
            $steps[] = array_merge($descriptor->toArray(), [
                'depends_on_kinds' => $descriptor->kind === 'content' ? ['outline'] : [],
            ]);
        }

        return $steps;
    }

    public function findDescriptor(SeoProjectTask $projectTask, string $nodeId): ?ContentProjectStepDescriptor
    {
        $nodeId = trim($nodeId);
        foreach ($this->listStepDescriptors($projectTask, false) as $descriptor) {
            if ($descriptor->nodeId === $nodeId) {
                return $descriptor;
            }
        }

        return null;
    }

    /**
     * Generic picker: rerunnable, không phải ba article writing actions chính.
     *
     * @return list<ContentProjectStepDescriptor>
     */
    public function listGenericPickerSteps(SeoProjectTask $projectTask): array
    {
        $primary = [
            WorkflowExecutionRole::ArticleOutlineGenerate->value,
            WorkflowExecutionRole::ArticleContentGenerate->value,
        ];

        $rows = [];
        foreach ($this->listStepDescriptors($projectTask, true) as $descriptor) {
            if ($descriptor->executionRole !== null && in_array($descriptor->executionRole, $primary, true)) {
                continue;
            }
            if (in_array($descriptor->kind, ['outline', 'content'], true) && $descriptor->executionRole !== null) {
                continue;
            }
            $rows[] = $descriptor;
        }

        return $rows;
    }

    public function firstPromptNodeIdForRole(SeoProjectTask $projectTask, WorkflowExecutionRole $role): ?string
    {
        $seoTask = $this->resolveSeoTaskForStepRetry($projectTask);
        if (! $seoTask instanceof SeoTask) {
            return null;
        }

        return $this->roleResolver->findNode($seoTask, $role)['node_id'] ?? null;
    }

    public function hasRole(SeoProjectTask $projectTask, WorkflowExecutionRole $role): bool
    {
        return $this->firstPromptNodeIdForRole($projectTask, $role) !== null;
    }

    /**
     * @param  list<string>  $nodeIds
     * @return list<string>
     */
    public function orderNodeIdsByDependency(SeoProjectTask $projectTask, array $nodeIds): array
    {
        $catalog = $this->listRerunnableSteps($projectTask);
        $byId = [];
        foreach ($catalog as $step) {
            $byId[$step['node_id']] = $step;
        }

        $selected = [];
        foreach ($nodeIds as $nodeId) {
            $id = trim((string) $nodeId);
            if ($id === '' || ! isset($byId[$id])) {
                continue;
            }
            $selected[] = $byId[$id];
        }

        usort($selected, static function (array $left, array $right): int {
            $leftRank = $left['kind'] === 'outline' ? 0 : ($left['kind'] === 'content' ? 1 : 2);
            $rightRank = $right['kind'] === 'outline' ? 0 : ($right['kind'] === 'content' ? 1 : 2);
            if ($leftRank !== $rightRank) {
                return $leftRank <=> $rightRank;
            }

            return strcmp((string) $left['node_id'], (string) $right['node_id']);
        });

        return array_values(array_map(
            static fn (array $step): string => (string) $step['node_id'],
            $selected,
        ));
    }

    /**
     * Node prompt đầu tiên theo kind (outline|content|…).
     */
    public function firstPromptNodeIdForKind(SeoProjectTask $projectTask, string $kind): ?string
    {
        $kind = $this->normalizeRerunKind($kind);
        $role = match ($kind) {
            'outline' => WorkflowExecutionRole::ArticleOutlineGenerate,
            'content' => WorkflowExecutionRole::ArticleContentGenerate,
            'improve' => WorkflowExecutionRole::ArticleContentImprove,
            'image' => WorkflowExecutionRole::ArticleImageGenerate,
            default => null,
        };
        if ($role instanceof WorkflowExecutionRole) {
            $byRole = $this->firstPromptNodeIdForRole($projectTask, $role);
            if ($byRole !== null) {
                return $byRole;
            }
        }

        foreach ($this->listRerunnableSteps($projectTask) as $step) {
            if (($step['kind'] ?? '') === $kind) {
                return (string) $step['node_id'];
            }
        }

        return null;
    }

    /**
     * Prompt node ids từ kind bắt đầu trở đi.
     *
     * @return list<string>
     */
    public function promptNodeIdsFromKindInclusive(SeoProjectTask $projectTask, string $kind): array
    {
        $kind = $this->normalizeRerunKind($kind);
        $startRank = $this->kindRank($kind);
        $ids = [];
        foreach ($this->listRerunnableSteps($projectTask) as $step) {
            $stepKind = (string) ($step['kind'] ?? 'prompt');
            if ($this->kindRank($stepKind) < $startRank) {
                continue;
            }
            $ids[] = (string) $step['node_id'];
        }

        return $this->orderNodeIdsByDependency($projectTask, $ids);
    }

    public function normalizeRerunKind(string $kind): string
    {
        $kind = trim(mb_strtolower($kind));

        return match ($kind) {
            'article', 'content' => 'content',
            'outline', 'dan_y', 'dàn ý' => 'outline',
            default => $kind,
        };
    }

    public function kindRank(string $kind): int
    {
        return match ($this->normalizeRerunKind($kind)) {
            'outline' => 0,
            'content' => 1,
            default => 2,
        };
    }

    public function resolveSeoTask(SeoProjectTask $projectTask): ?SeoTask
    {
        // Phase 0.6: CREATE / REWRITE dùng publish (article writing).
        // IMPROVE → ArticleImproveExecutionService (Settings article.content.improve) — không Publish.
        // Legacy rewrite_article_task_id giữ DB — không đọc runtime.
        if (SeoProjectTask::normalizeType((string) $projectTask->type) === SeoProjectTask::TYPE_IMPROVE) {
            return null;
        }

        $taskId = $this->settings->getPublishArticleTaskId();

        if ($taskId === null) {
            return null;
        }

        $task = SeoTask::query()->find($taskId);

        return $task instanceof SeoTask ? $task : null;
    }

    /**
     * Workflow dùng cho menu «Chạy lại từng prompt».
     * Improve không có Publish step catalog.
     */
    public function resolveSeoTaskForStepRetry(SeoProjectTask $projectTask): ?SeoTask
    {
        return $this->resolveSeoTask($projectTask);
    }

    public function findStep(SeoProjectTask $projectTask, string $nodeId): ?array
    {
        foreach ($this->listRerunnableSteps($projectTask) as $step) {
            if ($step['node_id'] === $nodeId) {
                return $step;
            }
        }

        return null;
    }

    private function countPromptNodes(?SeoTask $seoTask): int
    {
        if (! $seoTask instanceof SeoTask) {
            return 0;
        }

        $flow = is_array($seoTask->flow_data) ? $seoTask->flow_data : [];
        $nodes = is_array($flow['nodes'] ?? null) ? $flow['nodes'] : [];
        $count = 0;
        foreach ($nodes as $node) {
            if (! is_array($node)) {
                continue;
            }
            if ((string) ($node['type'] ?? '') === 'prompt') {
                $count++;
            }
        }

        return $count;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function detectKind(array $node, ?SeoPrompt $prompt, ?string $hookKey = null): string
    {
        $role = $this->roleResolver->readRole($node);
        if ($role instanceof WorkflowExecutionRole) {
            return $role->catalogKind();
        }

        $hook = $hookKey ?? $this->resolveHookKey($node, $prompt);
        if ($hook !== null) {
            $fromHook = $this->kindFromHookKey($hook);
            if ($fromHook !== null) {
                return $fromHook;
            }
        }

        $tools = $prompt !== null
            ? ImageToolType::fromMixed($prompt->tools ?? 'default')
            : ImageToolType::Default;

        if ($tools->isImagePipeline()) {
            return $tools->isTypography() ? 'typography' : 'image';
        }

        if ($tools === ImageToolType::Video) {
            return 'video';
        }

        // Phase 0.7+: không title heuristic. Node chưa gán role/hook → generic prompt.
        return 'prompt';
    }

    private function kindFromHookKey(string $hookKey): ?string
    {
        return match ($hookKey) {
            'article.outline.generate' => 'outline',
            'article.content.generate', 'article.content.rewrite' => 'content',
            'article.content.improve' => 'improve',
            'article.faq.generate' => 'faq',
            'article.featured_snippet.generate' => 'featured_snippet',
            'article.title_suggestion' => 'meta_title',
            'article.meta_description_suggestion' => 'meta_description',
            'article.comment.generate' => 'comment',
            'article.image.generate' => 'image',
            default => null,
        };
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function resolveHookKey(array $node, ?SeoPrompt $prompt): ?string
    {
        $data = is_array($node['data'] ?? null) ? $node['data'] : [];
        $fromNode = trim((string) ($data['hook_key'] ?? $data['hookKey'] ?? ''));
        if ($fromNode !== '') {
            return $fromNode;
        }

        if ($prompt instanceof SeoPrompt) {
            $fromPrompt = trim((string) ($prompt->hook_key ?? ''));
            if ($fromPrompt !== '') {
                return $fromPrompt;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function resolvePostType(array $node, ?SeoPrompt $prompt): ?string
    {
        $data = is_array($node['data'] ?? null) ? $node['data'] : [];
        $fromNode = trim((string) ($data['post_type'] ?? $data['postType'] ?? ''));
        if ($fromNode !== '') {
            return $fromNode;
        }

        if ($prompt instanceof SeoPrompt) {
            $settings = is_array($prompt->settings) ? $prompt->settings : [];
            $fromSettings = trim((string) ($settings['post_type'] ?? ''));
            if ($fromSettings !== '') {
                return $fromSettings;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function isRerunnablePromptNode(array $node, ?SeoPrompt $prompt, string $kind, ?string $hookKey): bool
    {
        $promptId = isset($node['data']['promptId']) ? (int) $node['data']['promptId'] : 0;
        if ($promptId <= 0 && ! $prompt instanceof SeoPrompt) {
            return false;
        }

        // Internal / control-like kinds không cho generic rerun.
        if (in_array($kind, ['merge', 'save', 'control'], true)) {
            return false;
        }

        // Prompt block thiếu identity (không role, không hook, không image tool) — vẫn cho nếu có prompt_id.
        return true;
    }

    private function labelForDescriptor(
        string $kind,
        ?string $hookKey,
        ?string $postType,
        ?WorkflowExecutionRole $role,
        string $title,
    ): string {
        if ($role instanceof WorkflowExecutionRole) {
            return match ($role) {
                WorkflowExecutionRole::ArticleOutlineGenerate => 'Chạy lại dàn ý',
                WorkflowExecutionRole::ArticleContentGenerate => 'Chạy lại bài viết',
                WorkflowExecutionRole::ArticleContentImprove => 'Chạy lại cải thiện bài',
                WorkflowExecutionRole::ArticleImageGenerate => 'Chạy lại hình ảnh',
            };
        }

        return match ($kind) {
            'outline' => 'Chạy lại dàn ý',
            'content' => 'Chạy lại bài viết',
            'improve' => 'Chạy lại cải thiện bài',
            'image' => 'Tạo lại ảnh bài viết',
            'typography' => 'Tạo lại typography',
            'infographic' => 'Tạo lại infographic',
            'product_gallery' => 'Tạo lại gallery sản phẩm',
            'meta_title' => 'Chạy lại meta SEO (title)',
            'meta_description' => 'Chạy lại meta SEO',
            'slug' => 'Chạy lại slug',
            'faq' => 'Chạy lại FAQ',
            'featured_snippet' => 'Chạy lại Featured Snippet',
            'video' => 'Tạo lại video',
            'comment' => 'Chạy lại bình luận',
            default => 'Chạy lại: '.($hookKey ?: $title),
        };
    }

    /**
     * @param  list<array<string, mixed>>  $edges
     * @return array<string, list<string>>
     */
    private function buildAdjacency(array $edges): array
    {
        $adj = [];
        foreach ($edges as $edge) {
            if (! is_array($edge)) {
                continue;
            }
            $source = trim((string) ($edge['source'] ?? $edge['from'] ?? ''));
            $target = trim((string) ($edge['target'] ?? $edge['to'] ?? ''));
            if ($source === '' || $target === '') {
                continue;
            }
            $adj[$source] ??= [];
            $adj[$source][] = $target;
        }

        return $adj;
    }

    /**
     * @param  array<string, list<string>>  $adjacency
     * @param  list<array{node_id: string}>  $promptNodes
     * @return list<string>
     */
    private function downstreamPromptNodeIds(string $nodeId, array $adjacency, array $promptNodes): array
    {
        $promptIds = [];
        foreach ($promptNodes as $row) {
            $promptIds[(string) $row['node_id']] = true;
        }

        $out = [];
        $queue = $adjacency[$nodeId] ?? [];
        $seen = [$nodeId => true];
        while ($queue !== []) {
            $current = array_shift($queue);
            if ($current === null || isset($seen[$current])) {
                continue;
            }
            $seen[$current] = true;
            if (isset($promptIds[$current])) {
                $out[] = $current;
            }
            foreach ($adjacency[$current] ?? [] as $next) {
                $queue[] = $next;
            }
        }

        return $out;
    }

    private function labelForKind(string $kind, string $title): string
    {
        return $this->labelForDescriptor($kind, null, null, null, $title);
    }
}
