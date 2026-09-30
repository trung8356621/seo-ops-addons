<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Tools\Handlers;

use InvalidArgumentException;
use Omnichannel\Addons\SearchIntelligence\Services\Topic\TopicServiceApiWriteService;
use Omnichannel\Addons\Seo\Services\Tools\SeoToolContext;
use Omnichannel\Addons\Seo\Services\Tools\SeoToolExecutionResult;
use Omnichannel\Addons\Seo\Services\Tools\SeoToolHandlerInterface;
use Throwable;

final class TopicCreateToolHandler implements SeoToolHandlerInterface
{
    public const TOOL_KEY = 'topic.create';

    public function __construct(
        private readonly TopicServiceApiWriteService $writeService
    ) {}

    public function getToolKey(): string
    {
        return self::TOOL_KEY;
    }

    /**
     * @param  SeoToolContext  $context
     * @param  array<string, mixed>  $input
     * @param  bool  $confirmed
     * @return SeoToolExecutionResult
     */
    public function execute(SeoToolContext $context, array $input, bool $confirmed = false): SeoToolExecutionResult
    {
        $siteId = $context->resolvedSiteId;
        if ($siteId === null || $siteId <= 0) {
            return SeoToolExecutionResult::failure(
                errorCode: 'missing_context',
                errorMessage: 'A valid site context is required.',
                httpStatus: 422
            );
        }

        $name = isset($input['name']) && is_string($input['name']) ? trim($input['name']) : '';
        if ($name === '') {
            return SeoToolExecutionResult::failure(
                errorCode: 'invalid_topic_name',
                errorMessage: 'Topic name must be a non-empty string.',
                httpStatus: 422
            );
        }

        try {
            $outcome = $this->writeService->create($siteId, $name);
            if (! ($outcome['ok'] ?? false)) {
                $errorCode = (string) ($outcome['error'] ?? 'topic_create_failed');
                $message = match ($errorCode) {
                    'invalid_topic_name' => 'The provided topic name is invalid.',
                    'topic_locked' => 'This topic is locked against modification.',
                    default => 'Topic creation failed.',
                };

                return SeoToolExecutionResult::failure(
                    errorCode: $errorCode,
                    errorMessage: $message,
                    httpStatus: 422
                );
            }

            return SeoToolExecutionResult::success(
                data: $outcome['data'] ?? [],
                meta: [
                    'topic_ref' => $outcome['data']['topic_ref'] ?? null,
                    'topic_id' => $outcome['data']['topic_id'] ?? null,
                    'reused' => $outcome['data']['reused'] ?? false,
                ]
            );
        } catch (InvalidArgumentException $e) {
            return SeoToolExecutionResult::failure(
                errorCode: 'validation_failed',
                errorMessage: $e->getMessage(),
                httpStatus: 422
            );
        } catch (Throwable $e) {
            return SeoToolExecutionResult::failure(
                errorCode: 'execution_failed',
                errorMessage: 'Topic creation failed.',
                httpStatus: 500
            );
        }
    }
}
