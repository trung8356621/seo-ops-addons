<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Social\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutingException;
use Omnichannel\Addons\Social\Ai\Exceptions\SocialAiException;
use Omnichannel\Addons\Social\Ai\Exceptions\SocialAiValidationException;
use Omnichannel\Addons\Social\Ai\Services\SocialAiExecutionService;
use Omnichannel\Addons\Social\Ai\SocialAiTaskRegistry;
use Omnichannel\Addons\Social\Ai\Tasks\SocialCommentGenerateTask;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class SocialCommentGenerateTaskTest extends TestCase
{
    private SocialCommentGenerateTask $task;

    protected function setUp(): void
    {
        parent::setUp();
        $this->task = new SocialCommentGenerateTask();
    }

    public function test_task_metadata_and_profile(): void
    {
        self::assertSame('social.comment.generate', $this->task->taskKey());
        self::assertSame('text.generate', $this->task->capability());
        self::assertSame('text.fast', $this->task->routingProfile());
    }

    public function test_1_plain_text_context_normalizes_and_compiles_prompt(): void
    {
        $input = [
            'context' => "Tiêu đề: Balo laptop 15.6 inch NATOLI\nMô tả: Cặp đi học nam chống sốc, chống nước",
            'social' => 'threads',
            'quantity' => 3,
        ];

        $normalized = $this->task->normalizeInput($input);
        self::assertSame("Tiêu đề: Balo laptop 15.6 inch NATOLI\nMô tả: Cặp đi học nam chống sốc, chống nước", $normalized['context']);
        self::assertSame('threads', $normalized['social']);
        self::assertSame(3, $normalized['quantity']);

        $prompt = $this->task->buildCompiledPrompt($normalized);
        self::assertStringContainsString("Tiêu đề: Balo laptop 15.6 inch NATOLI", $prompt);
        self::assertStringContainsString("Mô tả: Cặp đi học nam chống sốc, chống nước", $prompt);
        self::assertStringContainsString('Nền tảng mạng xã hội: threads', $prompt);
        self::assertStringContainsString('Số lượng comment yêu cầu: 3', $prompt);
        self::assertStringContainsString('dưới 300 từ', $prompt);
        self::assertStringContainsString('"comments": [', $prompt);
    }

    public function test_2_task_has_no_url_crawler_or_seo_dependency(): void
    {
        $source = (string) file_get_contents(
            (new ReflectionClass(SocialCommentGenerateTask::class))->getFileName()
        );

        self::assertStringNotContainsString('Http::', $source);
        self::assertStringNotContainsString('crawl', strtolower($source));
        self::assertStringNotContainsString('wordpress', strtolower($source));
        self::assertStringNotContainsString('SeoPrompt', $source);
        self::assertStringNotContainsString('SeoTask', $source);
        self::assertStringNotContainsString('seo_prompts', $source);
        self::assertStringNotContainsString('seo_tasks', $source);
    }

    public function test_3_requested_quantity_equals_returned_quantity(): void
    {
        $json = json_encode([
            'comments' => [
                'Balo này dùng êm vai thật, xài 3 năm rồi vẫn mới.',
                'Đúng kiểu mua một lần xài cả thời học sinh, tiết kiệm bao nhiêu.',
                'Mình cũng đang dùng mẫu này, quai đệm dày không bị đau lưng.',
            ],
        ], JSON_UNESCAPED_UNICODE);

        $comments = $this->task->validateAndParseOutput((string) $json, 3);
        self::assertCount(3, $comments);
        self::assertSame('Balo này dùng êm vai thật, xài 3 năm rồi vẫn mới.', $comments[0]);
        self::assertSame('Đúng kiểu mua một lần xài cả thời học sinh, tiết kiệm bao nhiêu.', $comments[1]);
        self::assertSame('Mình cũng đang dùng mẫu này, quai đệm dày không bị đau lưng.', $comments[2]);
    }

    public function test_4_malformed_ai_response_is_handled(): void
    {
        // 4a. Markdown code fence wrapper is cleaned and parsed
        $wrapped = "```json\n".json_encode([
            'comments' => [
                'Comment 1',
                'Comment 2',
                'Comment 3',
            ],
        ])."\n```";

        $parsed = $this->task->validateAndParseOutput($wrapped, 3);
        self::assertCount(3, $parsed);
        self::assertSame('Comment 1', $parsed[0]);

        // 4b. Plain list fallback when AI returns bullet points instead of strict JSON
        $plainTextList = "1. Bình luận đầu tiên rất hay\n2. Bình luận thứ hai xuất sắc\n3. Bình luận thứ ba tự nhiên";
        $fallback = $this->task->validateAndParseOutput($plainTextList, 3);
        self::assertCount(3, $fallback);
        self::assertSame('Bình luận đầu tiên rất hay', $fallback[0]);
        self::assertSame('Bình luận thứ hai xuất sắc', $fallback[1]);
        self::assertSame('Bình luận thứ ba tự nhiên', $fallback[2]);

        // 4c. Empty or completely invalid text throws SocialAiValidationException
        $this->expectException(SocialAiValidationException::class);
        $this->task->validateAndParseOutput('   ', 3);
    }

    public function test_5_no_active_route_returns_controlled_error(): void
    {
        $rawExecutor = function () {
            throw AiRoutingException::noCandidate('text.fast', 'text.generate');
        };

        $registry = new SocialAiTaskRegistry();
        $registry->register($this->task);

        $service = new SocialAiExecutionService(
            aiText: null,
            taskRegistry: $registry,
            rawExecutor: $rawExecutor,
        );

        $this->expectException(SocialAiException::class);
        $this->expectExceptionMessage('Hiện chưa có model AI khả dụng để Gen comment.');

        $service->generateComments([
            'context' => 'Đầu tư một cái balo xài nguyên thời học sinh',
            'quantity' => 3,
        ]);
    }

    public function test_6_social_comment_generate_does_not_require_seo_db_or_models(): void
    {
        $taskSource = (string) file_get_contents(
            (new ReflectionClass(SocialCommentGenerateTask::class))->getFileName()
        );
        $serviceSource = (string) file_get_contents(
            (new ReflectionClass(SocialAiExecutionService::class))->getFileName()
        );
        $registrySource = (string) file_get_contents(
            (new ReflectionClass(SocialAiTaskRegistry::class))->getFileName()
        );

        foreach ([$taskSource, $serviceSource, $registrySource] as $src) {
            self::assertStringNotContainsString('SeoPrompt', $src);
            self::assertStringNotContainsString('SeoTask', $src);
            self::assertStringNotContainsString('seo_prompts', $src);
            self::assertStringNotContainsString('seo_tasks', $src);
            self::assertStringNotContainsString('omi_seo_ai', $src);
            self::assertStringNotContainsString('SeoDatabaseConnectionService', $src);
        }
    }
}
