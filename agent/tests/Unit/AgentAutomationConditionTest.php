<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Tests\Unit;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Automation\Services\DefaultAgentAutomationConditionEvaluator;
use PHPUnit\Framework\TestCase;

final class AgentAutomationConditionTest extends TestCase
{
    private DefaultAgentAutomationConditionEvaluator $evaluator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->evaluator = new DefaultAgentAutomationConditionEvaluator;
    }

    public function test_numeric_comparison(): void
    {
        $result = $this->evaluator->evaluate(
            ['mode' => 'all', 'rules' => [['path' => 'count', 'operator' => 'greater_than', 'value' => 5]]],
            ['count' => 10],
            null,
            ['count'],
        );
        self::assertTrue($result->matched);
        self::assertSame([], $result->errors);
    }

    public function test_contains_and_in(): void
    {
        $contains = $this->evaluator->evaluate(
            ['mode' => 'all', 'rules' => [['path' => 'summary', 'operator' => 'contains', 'value' => 'ok']]],
            ['summary' => 'status ok'],
            null,
            ['summary'],
        );
        self::assertTrue($contains->matched);

        $in = $this->evaluator->evaluate(
            ['mode' => 'all', 'rules' => [['path' => 'status', 'operator' => 'in', 'value' => ['a', 'b']]]],
            ['status' => 'b'],
            null,
            ['status'],
        );
        self::assertTrue($in->matched);
    }

    public function test_all_any_modes(): void
    {
        $all = $this->evaluator->evaluate(
            [
                'mode' => 'all',
                'rules' => [
                    ['path' => 'a', 'operator' => 'equals', 'value' => 1],
                    ['path' => 'b', 'operator' => 'equals', 'value' => 2],
                ],
            ],
            ['a' => 1, 'b' => 9],
            null,
            ['a', 'b'],
        );
        self::assertFalse($all->matched);

        $any = $this->evaluator->evaluate(
            [
                'mode' => 'any',
                'rules' => [
                    ['path' => 'a', 'operator' => 'equals', 'value' => 1],
                    ['path' => 'b', 'operator' => 'equals', 'value' => 2],
                ],
            ],
            ['a' => 1, 'b' => 9],
            null,
            ['a', 'b'],
        );
        self::assertTrue($any->matched);
    }

    public function test_changed_increased_decreased(): void
    {
        $changed = $this->evaluator->evaluate(
            ['mode' => 'all', 'rules' => [['path' => 'count', 'operator' => 'changed']]],
            ['count' => 3],
            ['count' => 2],
            ['count'],
        );
        self::assertTrue($changed->matched);

        $up = $this->evaluator->evaluate(
            ['mode' => 'all', 'rules' => [['path' => 'count', 'operator' => 'increased']]],
            ['count' => 5],
            ['count' => 2],
            ['count'],
        );
        self::assertTrue($up->matched);

        $down = $this->evaluator->evaluate(
            ['mode' => 'all', 'rules' => [['path' => 'count', 'operator' => 'decreased']]],
            ['count' => 1],
            ['count' => 4],
            ['count'],
        );
        self::assertTrue($down->matched);
    }

    public function test_invalid_path(): void
    {
        $errors = $this->evaluator->validateSchema(
            ['mode' => 'all', 'rules' => [['path' => 'secret.token', 'operator' => 'equals', 'value' => 1]]],
            ['count'],
        );
        self::assertContains('invalid_path', $errors);
    }

    public function test_incompatible_types(): void
    {
        $result = $this->evaluator->evaluate(
            ['mode' => 'all', 'rules' => [['path' => 'count', 'operator' => 'greater_than', 'value' => 'abc']]],
            ['count' => 10],
            null,
            ['count'],
        );
        self::assertFalse($result->matched);
        self::assertContains('incompatible_types', $result->errors);
    }

    public function test_arbitrary_expression_rejected(): void
    {
        $errors = $this->evaluator->validateSchema(
            [
                'mode' => 'all',
                'rules' => [
                    ['path' => 'count', 'operator' => 'equals', 'value' => 1, 'php' => 'return true;'],
                ],
            ],
            ['count'],
        );
        self::assertContains('arbitrary_expression_rejected', $errors);
    }
}
