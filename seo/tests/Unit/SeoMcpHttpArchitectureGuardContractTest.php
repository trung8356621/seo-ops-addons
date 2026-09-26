<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Http\Controllers\ServiceApi\SeoMcpController;
use Omnichannel\Addons\Seo\Http\Middleware\EnsureSeoServiceApi;
use Omnichannel\Addons\Seo\Services\Mcp\Router\McpRouterReader;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

final class SeoMcpHttpArchitectureGuardContractTest extends TestCase
{
    public function test_http_layer_boundary_guards(): void
    {
        $controller = (string) file_get_contents(
            (string) (new ReflectionClass(SeoMcpController::class))->getFileName()
        );
        $middleware = (string) file_get_contents(
            (string) (new ReflectionClass(EnsureSeoServiceApi::class))->getFileName()
        );
        $routes = (string) file_get_contents(dirname(__DIR__, 2).'/routes/api-services.php');

        foreach ([$controller, $middleware, $routes] as $src) {
            $code = preg_replace('#/\*.*?\*/#s', '', $src) ?? $src;
            $code = preg_replace('#//.*$#m', '', $code) ?? $code;

            self::assertStringNotContainsString('->service_key', $code);
            self::assertStringNotContainsString('SiteService', $code);
            self::assertStringNotContainsString('settings.api_key', $code);
            self::assertStringNotContainsString('Http::', $code);
            self::assertStringNotContainsString('Illuminate\\Support\\Facades\\Http', $code);
            self::assertStringNotContainsString('AgentWorkspace', $code);
            self::assertStringNotContainsString('Services\\MonthlyMcp', $code);
            self::assertStringNotContainsString('MonthlyMcpSource', $code);
            self::assertStringNotContainsString('Agent\\Extension', $code);
        }

        self::assertStringContainsString('Site::query()', $controller);
        self::assertStringContainsString('McpRouterReader', $controller);
        self::assertStringContainsString('McpRouterRegistry', $controller);
        self::assertStringNotContainsString('Article::', $controller);
        self::assertStringNotContainsString('Keyword::', $controller);
        self::assertStringNotContainsString('SeoFinding', $controller);
    }

    public function test_reader_still_delegates_to_context_registry(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(McpRouterReader::class))->getFileName()
        );
        self::assertStringContainsString('contextRegistry()', $src);
        self::assertStringContainsString('->format(', $src);
        self::assertStringNotContainsString('Http::', $src);
        self::assertStringNotContainsString('Services\\MonthlyMcp', $src);
    }

    public function test_mcp_http_namespace_has_no_agent_or_monthly_imports(): void
    {
        $root = dirname(__DIR__, 2).'/src/Http';
        if (! is_dir($root)) {
            self::fail('SEO Http namespace missing');
        }
        foreach ($this->phpFiles($root) as $file) {
            $src = (string) file_get_contents($file);
            self::assertStringNotContainsString('AgentWorkspace', $src, $file);
            self::assertStringNotContainsString('Services\\MonthlyMcp', $src, $file);
            self::assertStringNotContainsString('ContentProjectMcpServer', $src, $file);
        }
    }

    /**
     * @return list<string>
     */
    private function phpFiles(string $root): array
    {
        $out = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));
        foreach ($iterator as $file) {
            if ($file->isFile() && str_ends_with($file->getFilename(), '.php')) {
                $out[] = $file->getPathname();
            }
        }

        return $out;
    }
}
