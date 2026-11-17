<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Tests\Support\ProjectRoot;

/**
 * Contract: parseHtmlToBlocks must keep .seo-managed-cta as one atomic block.
 * Unwrapping the div splits [seo_ops_cta] / body / [/seo_ops_cta] into three blocks.
 */
final class ManagedCtaBlockAtomicContractTest extends TestCase
{
    public function test_flatten_html_body_nodes_keeps_managed_cta_atomic(): void
    {
        $path = ProjectRoot::addonsPath().'/content/resources/js/utils/editorHtmlUtils.js';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('Managed CTA must stay atomic', $source);
        self::assertStringContainsString("classList?.contains('seo-managed-cta')", $source);
        self::assertMatchesRegularExpression(
            "/tag === 'div' && node\\.classList\\?\\.contains\\('seo-managed-cta'\\)/",
            $source,
        );
        // Must push the managed node before generic div unwrap.
        $atomicPos = strpos($source, "tag === 'div' && node.classList?.contains('seo-managed-cta')");
        $unwrapPos = strpos($source, "tag === 'div' || tag === 'section' || tag === 'article'");
        self::assertNotFalse($atomicPos);
        self::assertNotFalse($unwrapPos);
        self::assertLessThan($unwrapPos, $atomicPos);
    }

    public function test_cta_detector_wrap_emits_managed_container_contract(): void
    {
        $path = ProjectRoot::addonsPath().'/content/resources/js/utils/ctaDetector.js';
        self::assertFileExists($path);
        $source = (string) file_get_contents($path);
        self::assertStringContainsString('class="seo-managed-cta"', $source);
        self::assertStringContainsString('[seo_ops_cta style=', $source);
        self::assertStringContainsString('[/seo_ops_cta]', $source);
    }
}
