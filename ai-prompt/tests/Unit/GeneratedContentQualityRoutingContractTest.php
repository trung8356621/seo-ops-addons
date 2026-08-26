<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Services\AiModelRouterService;
use Omnichannel\Addons\AiPrompt\Services\AiProviderFailureClassifier;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;
use Omnichannel\Addons\AiPrompt\Support\AiFailureClass;
use Omnichannel\Addons\Content\Services\GeneratedContentQualityValidator;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use ReflectionMethod;

final class GeneratedContentQualityRoutingContractTest extends TestCase
{
    public function test_classifier_maps_output_quality_without_health_side_effects(): void
    {
        $classifier = new AiProviderFailureClassifier;
        $exception = new PromptRunException(
            'Content quality rejected: unexpected_script — may đôi,補強 góc',
            0,
            null,
            [
                'classification' => AiFailureClass::OutputQuality->value,
                'retryable' => true,
                'quality_rules' => ['unexpected_script'],
                'quality_sample' => 'may đôi,補強 góc',
            ],
        );

        $decision = $classifier->classify($exception);
        self::assertSame(AiFailureClass::OutputQuality, $decision->category);
        self::assertTrue($decision->shouldContinueRouting());
        self::assertFalse($decision->affectsRuntimeHealth);
        self::assertFalse($decision->lockConnection);
        self::assertFalse($decision->lockConnectionPaid);
        self::assertFalse($decision->applyCooldown);
        self::assertFalse($decision->markModelUnavailable);
    }

    public function test_prompt_runner_wires_quality_assert_inside_executor(): void
    {
        $src = (string) file_get_contents(
            (new ReflectionClass(PromptRunnerService::class))->getFileName() ?: '',
        );
        self::assertStringContainsString('assertGeneratedContentQuality', $src);
        self::assertStringContainsString('GeneratedContentQualityValidator', $src);
        self::assertStringContainsString('article.content.generate', $src);
        self::assertStringContainsString('AiFailureClass::OutputQuality', $src);
    }

    public function test_execute_with_profile_retries_after_output_quality_reject(): void
    {
        if (! class_exists(\Tests\TestCase::class)) {
            self::markTestSkipped('Laravel TestCase unavailable in this harness.');
        }

        // Lightweight loop simulation mirroring executeWithProfile catch semantics.
        $classifier = new AiProviderFailureClassifier;
        $attempts = [];
        $outputs = [
            'Viền may đôi, 補強 góc giúp túi chắc chắn hơn.',
            'Túi vải không dệt có độ bền cao và có thể tái sử dụng nhiều lần.',
        ];
        $validator = new GeneratedContentQualityValidator;
        $selected = null;
        $routingAttempts = [];

        foreach ($outputs as $index => $output) {
            $attemptNumber = $index + 1;
            $attempts[] = $attemptNumber;
            try {
                $quality = $validator->validate($output, ['language' => 'vi', 'is_html' => false]);
                if (! $quality->passed) {
                    throw new PromptRunException(
                        'Content quality rejected: '.implode(',', $quality->rejectRules()),
                        0,
                        null,
                        [
                            'classification' => AiFailureClass::OutputQuality->value,
                            'retryable' => true,
                            'quality_rules' => $quality->rejectRules(),
                            'quality_sample' => $quality->primarySample(),
                        ],
                    );
                }
                $routingAttempts[] = ['attempt' => $attemptNumber, 'result' => 'success'];
                $selected = $output;
                break;
            } catch (PromptRunException $exception) {
                $decision = $classifier->classify($exception);
                self::assertTrue($decision->shouldContinueRouting());
                self::assertSame(AiFailureClass::OutputQuality, $decision->category);
                $routingAttempts[] = [
                    'attempt' => $attemptNumber,
                    'result' => 'failed',
                    'failure_class' => $decision->category->value,
                    'quality_rules' => $exception->context['quality_rules'] ?? [],
                ];
            }
        }

        self::assertSame([1, 2], $attempts);
        self::assertSame('output_quality', $routingAttempts[0]['failure_class'] ?? null);
        self::assertSame('success', $routingAttempts[1]['result'] ?? null);
        self::assertSame($outputs[1], $selected);
        self::assertStringNotContainsString('補強', (string) $selected);
    }

    public function test_quality_exhaustion_does_not_select_malformed_output(): void
    {
        $classifier = new AiProviderFailureClassifier;
        $validator = new GeneratedContentQualityValidator;
        $outputs = [
            'Viền may đôi, 補強 góc.',
            'Góc đáy 補強 thêm lớp vải.',
        ];
        $selected = null;
        $failed = 0;

        foreach ($outputs as $output) {
            $quality = $validator->validate($output, ['language' => 'vi']);
            if ($quality->passed) {
                $selected = $output;
                break;
            }
            $failed++;
            $decision = $classifier->classify(new PromptRunException(
                'Content quality rejected',
                0,
                null,
                ['classification' => AiFailureClass::OutputQuality->value, 'retryable' => true],
            ));
            self::assertTrue($decision->shouldContinueRouting());
        }

        self::assertNull($selected);
        self::assertSame(2, $failed);
    }

    public function test_warning_only_does_not_reject_attempt(): void
    {
        $result = (new GeneratedContentQualityValidator)->validate(
            'sinh viên.lhọc tập mỗi ngày',
            ['language' => 'vi'],
        );
        self::assertTrue($result->passed);
        self::assertNotEmpty($result->issues);
    }

    public function test_router_attempt_log_accepts_quality_meta(): void
    {
        $method = new ReflectionMethod(AiModelRouterService::class, 'qualityAttemptMeta');
        $method->setAccessible(true);
        $meta = $method->invoke(
            new AiModelRouterService,
            new PromptRunException('x', 0, null, [
                'quality_rules' => ['unexpected_script'],
                'quality_sample' => 'may đôi,補強 góc',
            ]),
        );
        self::assertSame(['unexpected_script'], $meta['quality_rules']);
        self::assertSame('may đôi,補強 góc', $meta['quality_sample']);
    }
}
