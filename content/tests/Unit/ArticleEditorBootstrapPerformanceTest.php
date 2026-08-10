<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;


use Tests\Support\ProjectRoot;
use Omnichannel\Addons\Content\Services\ArticleEditor\ArticleEditorMediaSnapshotService;
use App\Models\WpOption;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

/**
 * Phase 2 performance â€” bootstrap query dedupe contracts (source + symbols).
 * Query-count ceilings for fixtures belong on remote PHPUnit with DB; this file
 * guards structural regressions that caused duplicate/N+1 loads.
 */
final class ArticleEditorBootstrapPerformanceTest extends TestCase
{
    public function test_core_bootstrap_reuses_analysis_policy_and_soft_media_snapshot(): void
    {
        $path = ProjectRoot::addonsPath().'/content/src/Filament/Resources/ArticleResource/Pages/EditArticle.php';
        $source = (string) file_get_contents($path);
        $body = $this->methodBody($source, 'public function getEditorCoreBootstrap(): array');
        self::assertNotSame('', $body);

        self::assertStringContainsString('->build($this->record', $body);
        self::assertStringContainsString(', false)', $body);
        self::assertStringContainsString('getEditorCoreSettingsPayload($analysisPolicy, $externalFacts)', $body);
        self::assertSame(1, substr_count($body, '->forArticle($this->record)'));
        self::assertSame(1, substr_count($body, '->externalFacts($this->record)'));

        foreach ([
            "'articleId'", "'mediaSnapshot'", "'analysisPolicy'", "'externalFacts'",
            "'endpoints'", "'editorDocument'", "'expectedContentHash'",
        ] as $needle) {
            self::assertStringContainsString($needle, $body);
        }
    }

    public function test_media_snapshot_batches_seo_media_and_supports_soft_refresh(): void
    {
        $class = new ReflectionClass(ArticleEditorMediaSnapshotService::class);
        self::assertTrue($class->hasMethod('build'));
        self::assertTrue($class->hasMethod('primeSeoMediaLookup'));

        $build = $this->methodSource(new ReflectionMethod(ArticleEditorMediaSnapshotService::class, 'build'));
        self::assertStringContainsString('bool $refresh = true', $build);
        self::assertStringContainsString('primeSeoMediaLookup', $build);

        $prime = $this->methodSource(new ReflectionMethod(ArticleEditorMediaSnapshotService::class, 'primeSeoMediaLookup'));
        self::assertStringContainsString('whereIn', $prime);
        self::assertStringContainsString('wp_attachment_id', $prime);

        $find = $this->methodSource(new ReflectionMethod(ArticleEditorMediaSnapshotService::class, 'findSeoMedia'));
        self::assertStringContainsString('seoMediaByKey', $find);
    }

    public function test_wp_option_request_cache_is_shared_between_get_and_set(): void
    {
        $source = (string) file_get_contents((new ReflectionClass(WpOption::class))->getFileName());
        self::assertStringContainsString('private static array $requestCache', $source);
        self::assertStringContainsString('clearRequestCache', $source);
        self::assertStringContainsString('unset(self::$requestCache[$name])', $source);
        self::assertStringContainsString('array_key_exists($name, self::$requestCache)', $source);
    }

    public function test_html_fallback_path_still_present_in_document_writer(): void
    {
        $writer = (string) file_get_contents(
            ProjectRoot::addonsPath().'/content/src/Services/ArticleEditor/Document/ArticleEditorDocumentWriter.php',
        );
        self::assertStringContainsString('resolveForBootstrap', $writer);
        self::assertStringContainsString('body_html', $writer);
        self::assertStringContainsString('STATUS_STALE', $writer);
        self::assertStringContainsString('isUsableBootstrapDocument', $writer);
    }

    /**
     * @return string
     */
    private function methodBody(string $source, string $signature): string
    {
        $pos = strpos($source, $signature);
        if ($pos === false) {
            return '';
        }

        $brace = strpos($source, '{', $pos);
        if ($brace === false) {
            return '';
        }

        $depth = 0;
        $len = strlen($source);
        for ($i = $brace; $i < $len; $i++) {
            $ch = $source[$i];
            if ($ch === '{') {
                $depth++;
            } elseif ($ch === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $brace, $i - $brace + 1);
                }
            }
        }

        return '';
    }

    private function methodSource(ReflectionMethod $method): string
    {
        $file = (string) $method->getFileName();
        $start = (int) $method->getStartLine();
        $end = (int) $method->getEndLine();
        $lines = file($file);
        if ($lines === false) {
            return '';
        }

        return implode('', array_slice($lines, $start - 1, $end - $start + 1));
    }
}
