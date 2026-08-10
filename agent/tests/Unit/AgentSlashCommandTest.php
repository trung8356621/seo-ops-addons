<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentSkillRegistry;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\AgentWorkspaceApplicationService;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class AgentSlashCommandTest extends TestCase
{
    private AgentSkillRegistry $registry;

    protected function setUp(): void
    {
        parent::setUp();
        $this->registry = new AgentSkillRegistry;
    }

    public function test_search_vietnamese_create_content_project_finds_skill(): void
    {
        $results = $this->registry->search('Tạo Content Project');
        $keys = array_map(static fn ($s) => $s->key, $results);

        self::assertContains('content_project.create', $keys);
    }

    public function test_search_create_project_finds_skill(): void
    {
        $results = $this->registry->search('create-project');
        $keys = array_map(static fn ($s) => $s->key, $results);

        self::assertContains('content_project.create', $keys);
    }

    public function test_alias_resolves_to_create_skill(): void
    {
        $skill = $this->registry->resolveSlashCommand('/tao-project');

        self::assertNotNull($skill);
        self::assertSame('content_project.create', $skill->key);
        self::assertSame('/create-project', $skill->slashCommand);
    }

    public function test_open_skill_does_not_execute_via_gateway(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(AgentWorkspaceApplicationService::class))->getFileName(),
        );

        self::assertStringContainsString('function openSkill', $source);
        self::assertStringContainsString('function preview', $source);
        self::assertStringContainsString('function execute', $source);

        $openSkillBody = $this->extractMethodBody($source, 'openSkill');
        self::assertStringNotContainsString('gateway->execute', $openSkillBody);
        self::assertStringNotContainsString('$this->gateway->execute', $openSkillBody);
    }

    private function extractMethodBody(string $source, string $methodName): string
    {
        if (! preg_match('/function\s+'.$methodName.'\s*\([^)]*\)[^{]*\{/', $source, $match, PREG_OFFSET_CAPTURE)) {
            return '';
        }

        $start = $match[0][1] + strlen($match[0][0]);
        $depth = 1;
        $length = strlen($source);

        for ($i = $start; $i < $length; $i++) {
            $char = $source[$i];
            if ($char === '{') {
                $depth++;
            } elseif ($char === '}') {
                $depth--;
                if ($depth === 0) {
                    return substr($source, $start, $i - $start);
                }
            }
        }

        return '';
    }
}
