<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Extension\Contracts;

/**
 * Real pipeline boundary: a definition describes the ordered steps a content pipeline
 * runs through (outline/article/translate/review/image/seo_audit/custom — see
 * PipelineStepDriver::stage()), what content types it supports, and what it needs to run.
 */
interface PipelineDefinitionInterface
{
    /**
     * Registry key, e.g. "article", "rewrite", "improve", "translate", "product".
     */
    public function key(): string;

    public function name(): string;

    public function version(): string;

    /**
     * @return list<string>
     */
    public function supportedContentTypes(): array;

    /**
     * @return list<array{key: string, label: string, stage: string, required: bool}>
     */
    public function steps(): array;

    /**
     * @return list<string>
     */
    public function requiredCapabilities(): array;

    /**
     * @param  array<string, mixed>  $context
     * @return array{ok: bool, errors: list<string>}
     */
    public function validate(array $context): array;
}
