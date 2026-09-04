<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Services\WorkflowRoles;

use Omnichannel\Addons\ContentProjects\Enums\WorkflowExecutionRole;

/**
 * Registry duy nhất cho execution roles — Builder / validation / runtime / tests.
 */
final class WorkflowExecutionRoleRegistry
{
    public const NODE_DATA_KEY = 'execution_role';

    /**
     * @return list<array{
     *     key: string,
     *     label: string,
     *     label_vi: string,
     *     allowed_node_types: list<string>,
     *     allowed_hooks: list<string>,
     *     unique_per_workflow: bool,
     *     catalog_kind: string
     * }>
     */
    public function all(): array
    {
        $rows = [];
        foreach (WorkflowExecutionRole::cases() as $role) {
            $rows[] = $this->definition($role);
        }

        return $rows;
    }

    /**
     * @return array{
     *     key: string,
     *     label: string,
     *     label_vi: string,
     *     allowed_node_types: list<string>,
     *     allowed_hooks: list<string>,
     *     unique_per_workflow: bool,
     *     catalog_kind: string
     * }
     */
    public function definition(WorkflowExecutionRole $role): array
    {
        return [
            'key' => $role->value,
            'label' => $role->labelEn(),
            'label_vi' => $role->labelVi(),
            'allowed_node_types' => ['prompt'],
            'allowed_hooks' => $this->allowedHooks($role),
            'unique_per_workflow' => true,
            'catalog_kind' => $role->catalogKind(),
        ];
    }

    /**
     * @return list<string>
     */
    public function allowedHooks(WorkflowExecutionRole $role): array
    {
        return match ($role) {
            WorkflowExecutionRole::ArticleOutlineGenerate => [
                'article.outline.generate',
                'article.outline.structure.generate',
            ],
            WorkflowExecutionRole::ArticleContentGenerate => [
                'article.content.generate',
                // Legacy rewrite remapped at runtime — cho phép gán role generate.
                'article.content.rewrite',
            ],
            WorkflowExecutionRole::ArticleContentImprove => ['article.content.improve'],
            WorkflowExecutionRole::ArticleImageGenerate => [
                'article.image.generate',
                'article.featured_image.generate',
                'product.gallery.generate',
            ],
        };
    }

    public function isHookAllowed(WorkflowExecutionRole $role, string $hookKey): bool
    {
        $hookKey = trim($hookKey);
        if ($hookKey === '') {
            return false;
        }

        if (str_contains($hookKey, '@')) {
            $hookKey = trim(explode('@', $hookKey, 2)[0]);
        }

        return in_array($hookKey, $this->allowedHooks($role), true);
    }

    public function suggestRoleFromHook(string $hookKey): ?WorkflowExecutionRole
    {
        $hookKey = trim($hookKey);
        if ($hookKey === '') {
            return null;
        }

        // Normalize versioned keys before match.
        if (str_contains($hookKey, '@')) {
            $hookKey = trim(explode('@', $hookKey, 2)[0]);
        }

        // Exact / canonical matches only — migration confidence cao.
        return match ($hookKey) {
            'article.outline.generate',
            'article.outline.structure.generate' => WorkflowExecutionRole::ArticleOutlineGenerate,
            'article.content.generate',
            'article.content.rewrite' => WorkflowExecutionRole::ArticleContentGenerate,
            'article.content.improve' => WorkflowExecutionRole::ArticleContentImprove,
            'article.image.generate',
            'article.featured_image.generate',
            'product.gallery.generate' => WorkflowExecutionRole::ArticleImageGenerate,
            default => null,
        };
    }

    /**
     * Options cho Workflow Builder (kèm «Không gán vai trò»).
     *
     * @return list<array{value: string, label: string}>
     */
    public function builderOptions(): array
    {
        $options = [
            ['value' => '', 'label' => 'Không gán vai trò'],
        ];
        foreach (WorkflowExecutionRole::cases() as $role) {
            $options[] = [
                'value' => $role->value,
                'label' => $role->labelVi(),
            ];
        }

        return $options;
    }
}
