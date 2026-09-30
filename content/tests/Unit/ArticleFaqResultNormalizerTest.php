<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleFaqResultNormalizer;
use Omnichannel\Addons\Content\Services\ArticleFaqGeneratorService;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

require_once dirname(__DIR__, 2).'/src/Services/ArticleFaqResultNormalizer.php';

final class ArticleFaqResultNormalizerTest extends TestCase
{
    public function test_legacy_faq_execution_enables_structured_json_routing(): void
    {
        $service = (new \ReflectionClass(ArticleFaqGeneratorService::class))->newInstanceWithoutConstructor();
        $method = new \ReflectionMethod(ArticleFaqGeneratorService::class, 'withLegacyStructuredExecutionContract');

        $variables = $method->invoke($service, ['title' => 'Article']);

        self::assertSame('article.faq.generate', $variables['_hook_key']);
        self::assertTrue($variables['_structured_output']);
        self::assertSame('json_mode', $variables['_structured_strategy']);
        self::assertSame('Article', $variables['title']);
    }

    public function test_normalizes_canonical_json_and_direct_lists(): void
    {
        $normalizer = new ArticleFaqResultNormalizer;
        self::assertSame([
            ['question' => 'FAQ 1?', 'answer' => 'Answer 1'],
        ], $normalizer->normalize('{"faqs":[{"question":" FAQ 1? ","answer":" Answer 1 "}]}'));
        self::assertSame([
            ['question' => 'FAQ 2?', 'answer' => 'Answer 2'],
        ], $normalizer->normalize([['question' => 'FAQ 2?', 'answer' => 'Answer 2']]));
    }

    #[DataProvider('invalidResults')]
    public function test_rejects_invalid_or_empty_structured_results(mixed $value, string $code): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($code);
        (new ArticleFaqResultNormalizer)->normalize($value);
    }

    public static function invalidResults(): array
    {
        return [
            'invalid json' => ['not json', 'FAQ_INVALID_JSON'],
            'missing faqs' => [[], 'FAQ_EMPTY_RESULT'],
            'empty faqs' => [['faqs' => []], 'FAQ_EMPTY_RESULT'],
            'malformed rows' => [['faqs' => [['question' => 'Missing answer']]], 'FAQ_EMPTY_RESULT'],
        ];
    }
}
