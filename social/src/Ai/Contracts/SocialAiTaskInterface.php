<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Social\Ai\Contracts;

interface SocialAiTaskInterface
{
    public function taskKey(): string;

    public function capability(): string;

    public function routingProfile(): string;

    public function systemPrompt(): string;

    /**
     * @param  array<string, mixed>  $rawInput
     * @return array<string, mixed>
     */
    public function normalizeInput(array $rawInput): array;

    /**
     * @param  array<string, mixed>  $normalized
     */
    public function buildCompiledPrompt(array $normalized): string;

    /**
     * @return list<string>
     */
    public function validateAndParseOutput(string $rawOutput, int $requestedQuantity): array;
}
