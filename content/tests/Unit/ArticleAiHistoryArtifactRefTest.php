<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleAiHistory\ArticleAiHistoryArtifactRef;
use PHPUnit\Framework\TestCase;

final class ArticleAiHistoryArtifactRefTest extends TestCase
{
    public function test_encode_and_parse_prompt_result_ref(): void
    {
        $ref = ArticleAiHistoryArtifactRef::encodePromptResult(42);
        self::assertSame('pr:42', $ref);

        $parsed = ArticleAiHistoryArtifactRef::parse($ref);
        self::assertSame([
            'kind' => 'pr',
            'prompt_result_id' => 42,
        ], $parsed);
    }

    public function test_encode_and_parse_run_item_step_ref(): void
    {
        $ref = ArticleAiHistoryArtifactRef::encodeRunItemStep(7, 2);
        self::assertSame('ri:7:2', $ref);

        $parsed = ArticleAiHistoryArtifactRef::parse($ref);
        self::assertSame([
            'kind' => 'ri',
            'run_item_id' => 7,
            'step_index' => 2,
        ], $parsed);
    }

    public function test_run_item_step_ref_allows_zero_step_index(): void
    {
        $parsed = ArticleAiHistoryArtifactRef::parse('ri:9:0');
        self::assertSame([
            'kind' => 'ri',
            'run_item_id' => 9,
            'step_index' => 0,
        ], $parsed);
    }

    /**
     * @dataProvider invalidRefProvider
     */
    public function test_parse_rejects_invalid_refs(string $ref): void
    {
        self::assertNull(ArticleAiHistoryArtifactRef::parse($ref));
    }

    /**
     * @return list<list<string>>
     */
    public static function invalidRefProvider(): array
    {
        return [
            [''],
            ['   '],
            ['pr:0'],
            ['pr:-1'],
            ['pr:abc'],
            ['ri:0:0'],
            ['ri:1:-1'],
            ['unknown:1'],
            ['pr:1:2'],
            ['run-1-task-2-step-3'],
        ];
    }
}
