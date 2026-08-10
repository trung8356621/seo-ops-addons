<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\Media\Support\ImageRoutingExecutionPolicy;
use Omnichannel\Addons\Media\Support\ImageToolType;
use App\Models\ApiConnection;
use Illuminate\Support\Str;

/**
 * Sinh N candidate typography — chỉ lưu temporary disk, không tạo seo_media.
 */
final class TypographyCandidateGenerationService
{
    public function __construct(
        private readonly GeminiMediaGenerationService $geminiMediaGeneration,
    ) {}

    /**
     * @return list<array{
     *     candidate_id: string,
     *     temporary_path: string,
     *     model_used: string,
     *     provider: string,
     *     attempt: int,
     *     usage: array<string, mixed>|null,
     *     resolution: string,
     *     generation_error: string|null,
     *     public_url: string,
     * }>
     */
    public function generateCandidates(
        ApiConnection $connection,
        string $compiledPrompt,
        ImageRoutingExecutionPolicy $policy,
        ImageToolType $toolType,
        bool $productContext,
        int $inputLength,
        ?\Omnichannel\Addons\Content\Support\TypographyComplexity $typographyComplexity,
        TypographyTemporaryStorageService $temporaryStorage,
        ?\Omnichannel\Addons\Seo\Support\RenderingPreference $preference = null,
    ): array {
        $targetCount = max(1, min(3, $policy->candidateCount));
        $candidates = [];
        $errors = [];

        for ($attempt = 1; $attempt <= $targetCount; $attempt++) {
            $candidateId = 'cand-'.Str::uuid()->toString();

            try {
                $result = $this->geminiMediaGeneration->generateImageBinary(
                    connection: $connection,
                    prompt: $this->appendResolutionHint($compiledPrompt, $policy->resolution, $attempt),
                    toolType: $toolType,
                    preference: $preference,
                    productContext: $productContext,
                    inputLength: $inputLength,
                    typographyComplexity: $typographyComplexity,
                    modelsOverride: $policy->models,
                );

                $binary = (string) ($result['binary'] ?? '');
                if ($binary === '') {
                    throw new PromptRunException('Candidate không trả binary ảnh.');
                }

                $mime = (string) ($result['mime'] ?? 'image/png');
                $tempPath = $temporaryStorage->store($binary, $mime, $candidateId);

                $candidates[] = [
                    'candidate_id' => $candidateId,
                    'temporary_path' => $tempPath,
                    'model_used' => (string) ($result['model_used'] ?? ''),
                    'provider' => (string) ($connection->provider ?? 'gemini'),
                    'attempt' => $attempt,
                    'usage' => is_array($result['usage'] ?? null) ? $result['usage'] : null,
                    'resolution' => $policy->resolution,
                    'generation_error' => null,
                    'public_url' => '',
                ];
            } catch (\Throwable $exception) {
                $errors[] = $exception->getMessage();
                $candidates[] = [
                    'candidate_id' => $candidateId,
                    'temporary_path' => '',
                    'model_used' => '',
                    'provider' => (string) ($connection->provider ?? 'gemini'),
                    'attempt' => $attempt,
                    'usage' => null,
                    'resolution' => $policy->resolution,
                    'generation_error' => mb_substr($exception->getMessage(), 0, 500),
                    'public_url' => '',
                ];
            }
        }

        $valid = array_values(array_filter(
            $candidates,
            static fn (array $item): bool => trim((string) ($item['temporary_path'] ?? '')) !== '',
        ));

        if ($valid === []) {
            $message = $errors !== [] ? implode(' | ', array_slice($errors, 0, 3)) : 'Không sinh được candidate typography.';

            throw new PromptRunException($message);
        }

        return $candidates;
    }

    private function appendResolutionHint(string $prompt, string $resolution, int $attempt): string
    {
        $suffix = "\n\n[render: resolution={$resolution}; candidate={$attempt}]";

        return $prompt.$suffix;
    }
}
