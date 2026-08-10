<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Tests\Unit;

use Omnichannel\Addons\ContentProjects\Support\ProjectTaskSourceKeyGenerator;
use PHPUnit\Framework\TestCase;

final class ProjectTaskSourceKeyGeneratorTest extends TestCase
{
    private ProjectTaskSourceKeyGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new ProjectTaskSourceKeyGenerator;
    }

    public function test_same_input_produces_same_key(): void
    {
        $a = $this->generator->generate(1, 'new_keyword', 'article', 'hello world');
        $b = $this->generator->generate(1, 'new_keyword', 'article', 'hello world');

        $this->assertSame($a, $b);
        $this->assertSame(64, strlen($a));
    }

    public function test_different_project_produces_different_key(): void
    {
        $a = $this->generator->generate(1, 'new_keyword', 'article', 'hello');
        $b = $this->generator->generate(2, 'new_keyword', 'article', 'hello');

        $this->assertNotSame($a, $b);
    }

    public function test_different_type_produces_different_key(): void
    {
        $a = $this->generator->generate(1, 'new_keyword', 'article', 'hello');
        $b = $this->generator->generate(1, 'rewrite', 'article', 'hello');

        $this->assertNotSame($a, $b);
    }

    public function test_different_post_type_produces_different_key(): void
    {
        $a = $this->generator->generate(1, 'new_keyword', 'article', 'hello');
        $b = $this->generator->generate(1, 'new_keyword', 'product', 'hello');

        $this->assertNotSame($a, $b);
    }

    public function test_whitespace_variants_normalize_to_same_key(): void
    {
        $a = $this->generator->generate(1, 'new_keyword', 'article', "hello   world");
        $b = $this->generator->generate(1, 'new_keyword', 'article', "  hello world  ");
        $c = $this->generator->generate(1, 'new_keyword', 'article', "hello\t\nworld");

        $this->assertSame($a, $b);
        $this->assertSame($a, $c);
    }

    public function test_vietnamese_unicode_case_and_whitespace(): void
    {
        $a = $this->generator->generate(10, 'new_keyword', 'article', 'Cặp học sinh Thiên Bình');
        $b = $this->generator->generate(10, 'new_keyword', 'article', ' CẶP HỌC SINH THIÊN BÌNH ');

        $this->assertSame($a, $b);
        $this->assertSame(
            'cặp học sinh thiên bình',
            $this->generator->normalizeSourceContent(' CẶP HỌC SINH THIÊN BÌNH '),
        );
    }

    public function test_punctuation_difference_keeps_distinct_keys(): void
    {
        $a = $this->generator->generate(1, 'new_keyword', 'article', 'hello world');
        $b = $this->generator->generate(1, 'new_keyword', 'article', 'hello world!');

        $this->assertNotSame($a, $b);
    }

    public function test_null_and_empty_are_deterministic(): void
    {
        $a = $this->generator->generate(1, null, null, null);
        $b = $this->generator->generate(1, '', '', '');
        $c = $this->generator->generate(1, null, '', null);

        $this->assertSame($a, $b);
        $this->assertSame($a, $c);
        $this->assertSame('', $this->generator->normalizeSourceContent(null));
        $this->assertSame('', $this->generator->normalizeSourceContent('   '));
    }

    public function test_pipe_in_source_does_not_create_ambiguity_with_structure(): void
    {
        $a = $this->generator->generate(1, 'a|b', 'c', 'd');
        $b = $this->generator->generate(1, 'a', 'b|c', 'd');

        $this->assertNotSame($a, $b);
    }
}
