<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Cli\AgentCliCommandCatalog;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\Cli\AgentCliCommandParser;
use Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\ContentProjectPublicRef;
use PHPUnit\Framework\TestCase;

final class AgentCliCommandTest extends TestCase
{
    public function test_catalog_has_static_project_run_command(): void
    {
        $def = AgentCliCommandCatalog::get('/project-run');
        self::assertNotNull($def);
        self::assertSame('content_project.generate', $def['skill_key']);
        self::assertStringContainsString('--project-id=31', $def['example']);
    }

    public function test_catalog_has_core_commands(): void
    {
        foreach (['/help', '/new-chat', '/context', '/site-health', '/daily-report', '/operation-status'] as $cmd) {
            self::assertNotNull(AgentCliCommandCatalog::get($cmd), $cmd);
        }
    }

    public function test_catalog_search_filters_by_prefix(): void
    {
        $rows = AgentCliCommandCatalog::search('proj');
        $commands = array_column($rows, 'command');
        self::assertContains('/project-list', $commands);
        self::assertContains('/project-run', $commands);
        self::assertNotContains('/member-list', $commands);
    }

    public function test_build_template_orders_required_before_optional(): void
    {
        $def = AgentCliCommandCatalog::get('/project-create');
        self::assertNotNull($def);
        $template = AgentCliCommandCatalog::buildTemplate($def);
        self::assertStringStartsWith('/project-create', $template);
        self::assertStringContainsString('--name=""', $template);
        self::assertStringContainsString('--month=""', $template);
        self::assertTrue(strpos($template, '--name=""') < strpos($template, '--member=""'));
    }

    public function test_parser_maps_project_id_to_opaque_ref(): void
    {
        $parser = new AgentCliCommandParser();
        $parsed = $parser->parse('/project-run --project-id=31');
        self::assertTrue($parsed['ok']);
        self::assertSame('content_project.generate', $parsed['skill_key']);
        self::assertSame(ContentProjectPublicRef::project(31), $parsed['inputs']['project_ref']);
    }

    public function test_parser_accepts_short_flag_p(): void
    {
        $parser = new AgentCliCommandParser();
        $parsed = $parser->parse('/project-view -p=12');
        self::assertTrue($parsed['ok']);
        self::assertSame(ContentProjectPublicRef::project(12), $parsed['inputs']['project_ref']);
    }

    public function test_keyword_tokens_mix_index_and_manual(): void
    {
        $parser = new AgentCliCommandParser();
        $context = [1 => 'kw-a', 3 => 'kw-c'];
        $parsed = $parser->parse('/keyword-add-to-project --project-id=5 1,3,"keyword mới"', $context);
        self::assertTrue($parsed['ok']);
        self::assertSame("kw-a\nkw-c\nkeyword mới", $parsed['inputs']['items_text']);
    }

    public function test_keyword_index_without_context_fails(): void
    {
        $parser = new AgentCliCommandParser();
        $parsed = $parser->parse('/keyword-add-to-project --project-id=5 1,3', []);
        self::assertFalse($parsed['ok']);
        self::assertSame('no_keyword_context', $parsed['error']);
    }

    public function test_each_command_has_description_and_example(): void
    {
        foreach (AgentCliCommandCatalog::all() as $row) {
            self::assertNotSame('', trim($row['description']), $row['command']);
            self::assertNotSame('', trim($row['example']), $row['command']);
        }
    }

    public function test_site_switch_accepts_domain_with_empty_site_id_placeholder(): void
    {
        $parser = new AgentCliCommandParser();
        $parsed = $parser->parse('/site-switch --site-id="" --domain="baloquatang.net"');
        self::assertTrue($parsed['ok'] ?? false);
        self::assertSame('/site-switch', $parsed['command'] ?? null);
        self::assertArrayNotHasKey('site_id', $parsed['inputs'] ?? []);
        self::assertSame('baloquatang.net', $parsed['inputs']['domain'] ?? null);
    }

    public function test_site_switch_accepts_site_id_alone(): void
    {
        $parser = new AgentCliCommandParser();
        $parsed = $parser->parse('/site-switch --site-id="5"');
        self::assertTrue($parsed['ok'] ?? false);
        self::assertSame('5', $parsed['inputs']['site_id'] ?? null);
        self::assertArrayNotHasKey('domain', $parsed['inputs'] ?? []);
    }

    public function test_site_switch_requires_site_id_or_domain(): void
    {
        $parser = new AgentCliCommandParser();
        $empty = $parser->parse('/site-switch --site-id="" --domain=""');
        self::assertFalse($empty['ok'] ?? true);
        self::assertSame('missing_required:site_id_or_domain', $empty['error'] ?? null);

        $bare = $parser->parse('/site-switch');
        self::assertFalse($bare['ok'] ?? true);
        self::assertSame('missing_required:site_id_or_domain', $bare['error'] ?? null);
    }
}
