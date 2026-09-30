<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\Tests\Unit;

use Omnichannel\Addons\Content\Services\ArticleFaqResultNormalizer;
use Omnichannel\Addons\Content\Services\ArticleFaqGeneratorService;
use Omnichannel\Addons\Content\Services\ArticleMarkdownToHtmlService;
use Omnichannel\Addons\Content\Support\SimpleMarkdownHtmlConverter;
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
        $normalizer = $this->normalizer();
        self::assertSame([
            ['question' => 'FAQ 1?', 'answer' => '<p>Answer 1</p>'],
        ], $normalizer->normalize('{"faqs":[{"question":" FAQ 1? ","answer":" Answer 1 "}]}'));
        self::assertSame([
            ['question' => 'FAQ 2?', 'answer' => '<p>Answer 2</p>'],
        ], $normalizer->normalize([['question' => 'FAQ 2?', 'answer' => 'Answer 2']]));
    }

    public function test_converts_markdown_answers_and_keeps_questions_plain(): void
    {
        $result = $this->normalizer()->normalize([
            ['question' => '**Quy trình** gồm những bước nào?', 'answer' => '**Thiết kế mẫu** và *Answered by Mr. A*'],
        ]);

        self::assertSame('**Quy trình** gồm những bước nào?', $result[0]['question']);
        self::assertSame(
            '<p><strong>Thiết kế mẫu</strong> và <em>Answered by Mr. A</em></p>',
            $result[0]['answer'],
        );
    }

    public function test_preserves_existing_answer_html(): void
    {
        $html = '<p><strong>Đã format</strong></p>';

        $result = $this->normalizer()->normalize([
            ['question' => 'FAQ?', 'answer' => $html],
        ]);

        self::assertSame($html, $result[0]['answer']);
    }

    #[DataProvider('invalidResults')]
    public function test_rejects_invalid_or_empty_structured_results(mixed $value, string $code): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage($code);
        $this->normalizer()->normalize($value);
    }

    public static function invalidResults(): array
    {
        return [
            'invalid json' => ['not json', 'FAQ_INVALID_JSON'],
            'missing faqs' => [[], 'FAQ_EMPTY_RESULT'],
            'empty faqs' => [['faqs' => []], 'FAQ_EMPTY_RESULT'],
            'malformed rows' => [['faqs' => [['question' => 'Missing answer']]], 'FAQ_EMPTY_RESULT'],
            'empty question' => [['faqs' => [['question' => ' ', 'answer' => 'Answer']]], 'FAQ_EMPTY_RESULT'],
            'empty answer' => [['faqs' => [['question' => 'Question?', 'answer' => ' ']]], 'FAQ_EMPTY_RESULT'],
        ];
    }

    private function normalizer(): ArticleFaqResultNormalizer
    {
        return new ArticleFaqResultNormalizer(
            new ArticleMarkdownToHtmlService(new SimpleMarkdownHtmlConverter),
        );
    }
}
