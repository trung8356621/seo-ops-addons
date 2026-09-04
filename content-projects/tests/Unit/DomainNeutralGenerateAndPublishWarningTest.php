<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Filament\Resources\SeoProjectResource\Concerns\InteractsWithDraftSplit;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Support\ContentProjectTenantGuard;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectExistingArticleReconciler;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\ContentProjectGenerationCapabilityResolver;
use Omnichannel\Addons\ContentProjects\Services\SeoProjectWorkflowRunService;
use Omnichannel\Addons\ContentProjects\Services\RunEngine\ContentProjectTaskExecutionService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use Tests\Support\LegacyAddonPath;

/**
 * Domain-neutral execution Generate + Publish domain warning + title WP link.
 */
final class DomainNeutralGenerateAndPublishWarningTest extends TestCase
{
    public function test_tenant_guard_allows_null_site_execution_projects(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectTenantGuard::class))->getFileName(),
        );
        self::assertStringNotContainsString("throw new RuntimeException('Project thiếu site_id.')", $src);
        self::assertStringContainsString('Domain-neutral project', $src);
        self::assertStringContainsString('canManageContentProjectWorkflow', $src);
    }

    public function test_workflow_scopes_articles_by_task_site_not_project_site(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(SeoProjectWorkflowRunService::class))->getFileName(),
        );
        self::assertStringContainsString('articleScopeForProject($taskSiteId)', $src);
        self::assertStringContainsString('Item #', $src);
        self::assertStringContainsString('thiếu site_id/domain', $src);
        self::assertStringNotContainsString("fromPlainDetail('Thiếu site_id.')", $src);
    }

    public function test_reconciler_prefers_task_site_over_project_site(): void
    {
        $method = new ReflectionMethod(ContentProjectExistingArticleReconciler::class, 'resolveSiteId');
        $file = (string) $method->getFileName();
        $lines = file($file);
        self::assertIsArray($lines);
        $chunk = implode('', array_slice(
            $lines,
            ((int) $method->getStartLine()) - 1,
            ((int) $method->getEndLine()) - ((int) $method->getStartLine()) + 1,
        ));
        self::assertStringContainsString('$task->site_id', $chunk);
        self::assertLessThan(
            strpos($chunk, '$task->project?->site_id') ?: PHP_INT_MAX,
            strpos($chunk, '$fromTask') ?: 0,
        );
    }

    public function test_capability_resolver_passes_task_site(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectGenerationCapabilityResolver::class))->getFileName(),
        );
        self::assertStringContainsString('(int) ($task->site_id ?? 0) > 0', $src);
    }

    public function test_task_execution_attach_uses_item_site(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(ContentProjectTaskExecutionService::class))->getFileName(),
        );
        self::assertStringContainsString('$itemSiteId = (int) ($task->site_id ?? $project->site_id ?? 0)', $src);
        self::assertStringNotContainsString("->where('site_id', (int) \$project->site_id)", $src);
    }

    public function test_item_meta_title_links_wp_permalink_not_editor(): void
    {
        $blade = LegacyAddonPath::read('resources/views/components/content-project-item-meta.blade.php');
        self::assertStringContainsString('article_public_url', $blade);
        self::assertStringContainsString('target="_blank"', $blade);
        self::assertStringContainsString('rel="noopener noreferrer"', $blade);
        self::assertStringContainsString('heroicon-o-arrow-top-right-on-square', $blade);
        // Title must not use internal editor URL.
        self::assertStringNotContainsString("\$url = \$row['article_edit_url']", $blade);
        self::assertStringNotContainsString('href="{{ $url }}"', $blade);
    }

    public function test_publish_domain_imbalance_warning_contract(): void
    {
        $trait = (string) file_get_contents(
            (string) (new ReflectionClass(InteractsWithDraftSplit::class))->getFileName(),
        );
        self::assertStringContainsString('publishDomainDistributionPreview', $trait);
        self::assertStringContainsString('domain_warning', $trait);
        self::assertStringContainsString('articles_per_day', $trait);
        self::assertStringContainsString('>= 60.0', $trait);
        self::assertStringContainsString('>= 30', $trait);
        self::assertStringContainsString('count($rows) >= 2', $trait);

        $blade = LegacyAddonPath::read('resources/views/components/content-project-draft-planner.blade.php');
        self::assertStringContainsString('data-split-domain-summary', $blade);
        self::assertStringContainsString('data-split-domain-warning', $blade);
        self::assertStringContainsString('draft_split_domain_warning_title', $blade);
        self::assertStringContainsString('draft_split_summary_line', $blade);
    }

    public function test_domain_warning_formula_examples(): void
    {
        // Pure formula checks (no DB): mirror the gate in publishDomainDistributionPreview.
        $cases = [
            // skewed multi-domain
            ['rows' => [97, 23, 5, 2], 'warn' => true],
            // balanced
            ['rows' => [30, 30, 30], 'warn' => false],
            // single domain — no warn even if large
            ['rows' => [90], 'warn' => false],
            // multi but under 60%
            ['rows' => [40, 35, 25], 'warn' => false],
            // multi 60%+ but under 30 items on top
            ['rows' => [20, 10], 'warn' => false],
        ];

        foreach ($cases as $case) {
            $rows = $case['rows'];
            $total = array_sum($rows);
            $top = max($rows);
            $percent = ($top / max(1, $total)) * 100;
            $warn = count($rows) >= 2 && $top >= 30 && $percent >= 60.0;
            self::assertSame($case['warn'], $warn, 'rows='.implode(',', $rows));
        }

        self::assertSame(3.2, round(97 / 30, 1));
    }
}
