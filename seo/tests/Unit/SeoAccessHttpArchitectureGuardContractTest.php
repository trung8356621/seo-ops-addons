<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Tests\Unit;

use Omnichannel\Addons\Seo\Http\Controllers\ServiceApi\SeoAccessController;
use Omnichannel\Addons\Seo\Http\Controllers\ServiceApi\TemporarySeoAccessController;
use Omnichannel\Addons\Seo\Http\Middleware\EnsureSeoServiceApi;
use Omnichannel\Addons\Seo\Services\Access\SeoAccessCatalog;
use Omnichannel\Addons\Seo\Services\Access\SeoAccessContentComposer;
use Omnichannel\Addons\Seo\Services\Access\SeoAccessGscComposer;
use Omnichannel\Addons\Seo\Services\Access\SeoAccessKeywordsComposer;
use Omnichannel\Addons\Seo\Services\Access\SeoAccessSiteKnowledgeComposer;
use Omnichannel\Addons\Seo\Services\Mcp\Router\McpRouterReader;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;

final class SeoAccessHttpArchitectureGuardContractTest extends TestCase
{
    public function test_http_layer_boundary_guards(): void
    {
        $controller = (string) file_get_contents(
            (string) (new ReflectionClass(SeoAccessController::class))->getFileName()
        );
        $temporary = (string) file_get_contents(
            (string) (new ReflectionClass(TemporarySeoAccessController::class))->getFileName()
        );
        $middleware = (string) file_get_contents(
            (string) (new ReflectionClass(EnsureSeoServiceApi::class))->getFileName()
        );
        $routes = (string) file_get_contents(dirname(__DIR__, 2).'/routes/api-services.php');
        $tempRoutes = (string) file_get_contents(dirname(__DIR__, 2).'/routes/api-access-temporary.php');

        foreach ([$controller, $temporary, $middleware, $routes, $tempRoutes] as $src) {
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

        self::assertStringContainsString('seo:read', $routes);
        self::assertStringContainsString('{service}/access', $routes);
        self::assertStringNotContainsString('{service}/mcp', $routes);
        self::assertStringNotContainsString('AuthenticateServiceApi', $temporary);
        self::assertDoesNotMatchRegularExpression(
            "/middleware:\\s*\\[[^\\]]*AuthenticateServiceApi/",
            $tempRoutes,
        );
    }

    public function test_catalog_is_exactly_four_public_resources(): void
    {
        $keys = array_column(SeoAccessCatalog::resources(), 'key');
        self::assertSame(['site', 'content', 'keywords', 'gsc'], $keys);
    }

    public function test_composers_reuse_gateways_not_http_loopback(): void
    {
        foreach ([
            SeoAccessContentComposer::class,
            SeoAccessKeywordsComposer::class,
            SeoAccessGscComposer::class,
            SeoAccessSiteKnowledgeComposer::class,
        ] as $class) {
            $src = (string) file_get_contents((string) (new ReflectionClass($class))->getFileName());
            self::assertStringNotContainsString('Http::', $src, $class);
            self::assertStringNotContainsString('Illuminate\\Support\\Facades\\Http', $src, $class);
            self::assertStringNotContainsString('McpRouterReader', $src, $class);
        }

        $keywords = (string) file_get_contents(
            (string) (new ReflectionClass(SeoAccessKeywordsComposer::class))->getFileName()
        );
        self::assertStringContainsString('KeywordLandscapeGateway', $keywords);
        self::assertStringContainsString('KeywordRelationshipGateway', $keywords);

        $gsc = (string) file_get_contents(
            (string) (new ReflectionClass(SeoAccessGscComposer::class))->getFileName()
        );
        self::assertStringContainsString('GscContextSource', $gsc);
    }

    public function test_internal_mcp_reader_still_delegates_to_context_registry(): void
    {
        $src = (string) file_get_contents(
            (string) (new ReflectionClass(McpRouterReader::class))->getFileName()
        );
        self::assertStringContainsString('contextRegistry()', $src);
        self::assertStringContainsString('->format(', $src);
        self::assertStringNotContainsString('Http::', $src);
        self::assertStringNotContainsString('Services\\MonthlyMcp', $src);
    }

    public function test_access_http_namespace_has_no_agent_or_monthly_imports(): void
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
