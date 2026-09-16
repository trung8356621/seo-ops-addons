<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Livewire\GlobalSeoBar;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;
use Omnichannel\Addons\Seo\Support\SeoPanelRoutes;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;
use ReflectionProperty;

/**
 * Active month chip must survive Livewire /livewire/update — not re-resolved from POST route.
 */
final class GlobalSeoBarPlannerActiveMonthVisibilityTest extends TestCase
{
    public function test_show_planner_flag_is_public_property_set_in_mount_not_render_route(): void
    {
        $src = (string) file_get_contents((string) (new ReflectionClass(GlobalSeoBar::class))->getFileName());
        $render = $this->methodSource(GlobalSeoBar::class, 'render');
        $mount = $this->methodSource(GlobalSeoBar::class, 'mount');

        self::assertTrue((new ReflectionProperty(GlobalSeoBar::class, 'showPlannerActiveMonth'))->isPublic());
        self::assertStringContainsString('public bool $showPlannerActiveMonth', $src);
        self::assertStringContainsString('isProjectPlannerSeoAuditPage()', $mount);
        self::assertStringContainsString('$this->showPlannerActiveMonth = SeoAccessControl::isProjectPlannerSeoAuditPage()', $mount);

        self::assertStringContainsString("'showPlannerActiveMonth' => \$this->showPlannerActiveMonth", $render);
        self::assertStringNotContainsString('SeoPanelRoutes::isProjectPlannerSeoAudit()', $render);
        self::assertStringNotContainsString('isProjectPlannerSeoAuditPage()', $render);
        self::assertStringNotContainsString('request()->headers->get(\'referer\'', $render);
    }

    public function test_domain_update_uses_stable_planner_flag_not_livewire_route(): void
    {
        $updated = $this->methodSource(GlobalSeoBar::class, 'updatedDomainKey');

        self::assertStringContainsString('$this->showPlannerActiveMonth', $updated);
        self::assertStringNotContainsString('SeoPanelRoutes::isProjectPlannerSeoAudit()', $updated);
    }

    public function test_access_control_page_helper_combines_route_and_path_for_initial_get(): void
    {
        $src = (string) file_get_contents((string) (new ReflectionClass(SeoAccessControl::class))->getFileName());
        self::assertStringContainsString('isProjectPlannerSeoAuditPage', $src);
        self::assertStringContainsString('isProjectPlannerSeoAuditPath', $src);

        self::assertTrue(method_exists(SeoPanelRoutes::class, 'isProjectPlannerSeoAudit'));
        self::assertTrue(method_exists(SeoPanelRoutes::class, 'isProjectPlannerSeoAuditPath'));
    }

    public function test_blade_gates_on_show_planner_active_month_variable(): void
    {
        $blade = (string) file_get_contents(
            dirname((string) (new ReflectionClass(GlobalSeoBar::class))->getFileName(), 3)
            .'/resources/views/livewire/global-seo-bar.blade.php',
        );

        self::assertStringContainsString('@if ($showPlannerActiveMonth ?? false)', $blade);
        self::assertStringContainsString('wire:model.live="plannerActiveMonth"', $blade);
        self::assertStringContainsString('data-planner-active-month="1"', $blade);
    }

    private function methodSource(string $class, string $method): string
    {
        $ref = new ReflectionMethod($class, $method);
        $lines = file((string) $ref->getFileName());
        self::assertIsArray($lines);

        return implode('', array_slice(
            $lines,
            $ref->getStartLine() - 1,
            $ref->getEndLine() - $ref->getStartLine() + 1,
        ));
    }
}
