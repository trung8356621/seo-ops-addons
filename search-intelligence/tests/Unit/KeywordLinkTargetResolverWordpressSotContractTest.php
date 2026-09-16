<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Tests\Unit;

use Omnichannel\Addons\SearchFoundation\Console\RepairArticleBackedKeywordTargetUrlsCommand;
use Omnichannel\Addons\SearchFoundation\Services\KeywordLinkTargetResolver;
use Omnichannel\Addons\SearchFoundation\Services\RepairArticleBackedKeywordTargetUrlService;
use Omnichannel\Addons\WordPress\Services\WordPressInternalLinkTargetPolicy;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class KeywordLinkTargetResolverWordpressSotContractTest extends TestCase
{
    public function test_resolver_constructor_uses_internal_link_policy(): void
    {
        $ctor = (new ReflectionClass(KeywordLinkTargetResolver::class))->getConstructor();
        self::assertNotNull($ctor);
        $types = array_map(
            static fn ($p) => $p->getType()?->getName(),
            $ctor->getParameters(),
        );

        self::assertContains(WordPressInternalLinkTargetPolicy::class, $types);
        self::assertNotContains(
            \Omnichannel\Addons\WordPress\Services\WordPressArticleContentService::class,
            $types,
        );
    }

    public function test_focus_destination_blocks_stale_meta_url_when_unsynced(): void
    {
        $body = $this->methodBody(KeywordLinkTargetResolver::class, 'resolveFocusArticleDestination');

        self::assertStringContainsString('mainArticleIdForSite', $body);
        self::assertStringContainsString("'handled' => true", $body);
        self::assertStringContainsString('resolveArticlePublicUrl', $body);
    }

    public function test_repair_command_is_idempotent_dry_run_capable(): void
    {
        $cmd = (string) file_get_contents(
            (string) (new ReflectionClass(RepairArticleBackedKeywordTargetUrlsCommand::class))->getFileName(),
        );
        $svc = (string) file_get_contents(
            (string) (new ReflectionClass(RepairArticleBackedKeywordTargetUrlService::class))->getFileName(),
        );

        self::assertStringContainsString('--dry-run', $cmd);
        self::assertStringContainsString('--force', $cmd);
        self::assertStringContainsString('--site_id=', $cmd);
        self::assertStringContainsString('Examined:', $cmd);
        self::assertStringContainsString('getMainArticleIdForSite', $svc);
        self::assertStringContainsString('skipped_manual', $svc);
    }

    /**
     * @param  class-string  $class
     */
    private function methodBody(string $class, string $method): string
    {
        $ref = new ReflectionClass($class);
        $m = new ReflectionMethod($class, $method);
        $lines = explode("\n", (string) file_get_contents((string) $ref->getFileName()));

        return implode("\n", array_slice(
            $lines,
            $m->getStartLine() - 1,
            $m->getEndLine() - $m->getStartLine() + 1,
        ));
    }
}
