<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Support\ProjectRunIdempotencyKeyGenerator;
use PHPUnit\Framework\TestCase;

final class ProjectRunIdempotencyKeyGeneratorTest extends TestCase
{
    private ProjectRunIdempotencyKeyGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new ProjectRunIdempotencyKeyGenerator;
    }

    public function test_same_task_action_version_produces_same_key(): void
    {
        $a = $this->generator->generate(42, 'article.create', 'v1');
        $b = $this->generator->generate(42, 'article.create', 'v1');

        $this->assertSame($a, $b);
        $this->assertSame(64, strlen($a));
    }

    public function test_different_operation_version_produces_different_key(): void
    {
        $a = $this->generator->generate(42, 'article.create', 'v1');
        $b = $this->generator->generate(42, 'article.create', 'v2');

        $this->assertNotSame($a, $b);
    }

    public function test_different_action_produces_different_key(): void
    {
        $a = $this->generator->generate(42, 'article.create', 'v1');
        $b = $this->generator->generate(42, 'article.rewrite', 'v1');

        $this->assertNotSame($a, $b);
    }

    public function test_content_version_canonicalizes_associative_key_order(): void
    {
        $a = $this->generator->contentVersion([
            'b' => 2,
            'a' => 1,
            'nested' => ['y' => 2, 'x' => 1],
        ]);
        $b = $this->generator->contentVersion([
            'a' => 1,
            'nested' => ['x' => 1, 'y' => 2],
            'b' => 2,
        ]);

        $this->assertSame($a, $b);
    }

    public function test_content_version_changes_when_value_changes(): void
    {
        $a = $this->generator->contentVersion(['source' => 'a']);
        $b = $this->generator->contentVersion(['source' => 'b']);

        $this->assertNotSame($a, $b);
    }
}
