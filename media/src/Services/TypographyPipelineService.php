<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\PromptMediaStorageService;
use Omnichannel\Addons\Media\Support\ImageModelInputLengthPolicy;
use Omnichannel\Addons\Media\Support\ImageRoutingExecutionPolicy;
use Omnichannel\Addons\Media\Support\ImageRoutingStrategy;
use Omnichannel\Addons\Media\Support\ImageToolType;
use Omnichannel\Addons\Seo\Support\RenderingPreference;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\Content\Support\TypographyComplexity;
use App\Models\ApiConnection;

/**
 * Orchestrator typography: parse → policy → candidates → validate → winner.
 */
final class TypographyPipelineService
{
    public function __construct(
        private readonly TypographyComplexityParser $complexityParser,
        private readonly ImageRoutingStrategy $imageRoutingStrategy,
        private readonly SeoCreateArticleSettingsService $workflowSettings,
        private readonly TypographyCandidateGenerationService $candidateGeneration,
        private readonly TypographyValidationService $validationService,
        private readonly TypographyTemporaryStorageService $temporaryStorage,
        private readonly PromptMediaStorageService $promptMediaStorage,
        private readonly GeminiMediaGenerationService $geminiMediaGeneration,
    ) {}

    /**
     * @param  array<string, string>  $variables
     * @return array{
     *     url: string,
     *     usage: array<string, mixed>|null,
     *     model_used: string,
     *     validation_model: string|null,
     *     candidate_count: int,
     *     winner_score: float|null,
     *     validation_passed: bool|null,
     *     validation_warning: bool,
     *     missing_text_count: int,
     *     mismatched_text_count: int,
     *     typography_complexity_summary: array<string, mixed>,
     *     metadata: array<string, mixed>,
     * }
     */
    public function execute(
        ApiConnection $connection,
        SeoPrompt $prompt,
        string $compiled,
        array $variables,
    ): array {
        $complexity = $this->complexityParser->parse($compiled, $variables);
        $preference = $this->workflowSettings->getRenderingPreference();
        $validationLevel = $this->workflowSettings->getTypographyValidationLevel();
        $validationEnabled = $this->workflowSettings->isTypographyValidationEnabled();

        $inputLength = ImageModelInputLengthPolicy::measureCompiledPromptLength($compiled);
        $policy = $this->imageRoutingStrategy->executionPolicy(
            toolType: ImageToolType::ImageTypography,
            preference: $validationLevel->toRenderingPreference(),
            typographyComplexity: $complexity,
            compiledPromptLength: $inputLength,
            productContext: $this->isProductImageContext($variables),
            configuredPriorityList: $this->workflowSettings->getTypographyModelPriority(),
            adminEnabledUnknownSlugs: $this->workflowSettings->getAdminEnabledUnknownImageModels(),
            validationEnabled: $validationEnabled,
            passThresholdOverride: $this->workflowSettings->getTypographyPassThreshold(),
            maxCandidatesOverride: $this->workflowSettings->getTypographyMaxCandidates(),
            allowGeneralImageFallback: $this->workflowSettings->allowTypographyGeneralImageFallback(),
            generalImageFallbackPriorityList: $this->workflowSettings->getImageModelPriority(),
        );

        if ($policy->models === []) {
            throw new PromptRunException(
                $this->workflowSettings->allowTypographyGeneralImageFallback()
                    ? 'Không có model image (typography hoặc general) đủ điều kiện. Kiểm tra Model Priority trong AI Advanced.'
                    : 'Không có model typography phù hợp. Bật General Image Fallback trong AI Advanced hoặc thêm model typography_supported.',
            );
        }

        if ($policy->candidateCount <= 1 && ! $policy->validationRequired) {
            return $this->executeSingleShot($connection, $compiled, $variables, $complexity, $preference, $policy);
        }

        return $this->executeMultiCandidate($connection, $compiled, $variables, $complexity, $preference, $policy);
    }

    /**
     * @param  array<string, string>  $variables
     * @return array<string, mixed>
     */
    private function executeSingleShot(
        ApiConnection $connection,
        string $compiled,
        array $variables,
        TypographyComplexity $complexity,
        RenderingPreference $preference,
        ImageRoutingExecutionPolicy $policy,
    ): array {
        $inputLength = ImageModelInputLengthPolicy::measureCompiledPromptLength($compiled);
        $result = $this->geminiMediaGeneration->generateImage(
            connection: $connection,
            prompt: $compiled,
            toolType: ImageToolType::ImageTypography,
            preference: $preference,
            productContext: $this->isProductImageContext($variables),
            inputLength: $inputLength,
            typographyComplexity: $complexity,
            modelsOverride: $policy->models,
        );

        return [
            'url' => (string) $result['url'],
            'usage' => is_array($result['usage'] ?? null) ? $result['usage'] : null,
            'model_used' => (string) ($result['model_used'] ?? ''),
            'validation_model' => null,
            'candidate_count' => 1,
            'winner_score' => null,
            'validation_passed' => null,
            'validation_warning' => $policy->typographyWarning,
            'missing_text_count' => 0,
            'mismatched_text_count' => 0,
            'typography_complexity_summary' => $complexity->summary(),
            'metadata' => [
                'execution_policy' => $policy->toArray(),
                'validation_owner' => 'none',
            ],
        ];
    }

    /**
     * @param  array<string, string>  $variables
     * @return array<string, mixed>
     */
    private function executeMultiCandidate(
        ApiConnection $connection,
        string $compiled,
        array $variables,
        TypographyComplexity $complexity,
        RenderingPreference $preference,
        ImageRoutingExecutionPolicy $policy,
    ): array {
        $inputLength = ImageModelInputLengthPolicy::measureCompiledPromptLength($compiled);
        $candidates = $this->candidateGeneration->generateCandidates(
            connection: $connection,
            compiledPrompt: $compiled,
            policy: $policy,
            toolType: ImageToolType::ImageTypography,
            productContext: $this->isProductImageContext($variables),
            inputLength: $inputLength,
            typographyComplexity: $complexity,
            temporaryStorage: $this->temporaryStorage,
            preference: $preference,
        );

        $validCandidates = array_values(array_filter(
            $candidates,
            static fn (array $item): bool => trim((string) ($item['temporary_path'] ?? '')) !== '',
        ));

        $scored = [];
        $validationModel = null;

        foreach ($validCandidates as $candidate) {
            $tempPath = (string) $candidate['temporary_path'];
            $absolute = $this->temporaryStorage->absolutePath($tempPath);

            if (! $policy->validationRequired) {
                $scored[] = [
                    'candidate' => $candidate,
                    'score' => 1.0,
                    'passed' => true,
                    'validation' => null,
                ];

                continue;
            }

            try {
                $validation = $this->validationService->validateCandidate(
                    connection: $connection,
                    imageAbsolutePath: $absolute,
                    visibleTextBlocks: $complexity->visibleTextBlocks,
                    complexity: $complexity,
                    minimumScore: $policy->minimumScore,
                    preferredModel: $this->workflowSettings->getTypographyValidationModel(),
                );
                $validationModel = (string) ($validation['validation_model'] ?? $validationModel);

                $scored[] = [
                    'candidate' => $candidate,
                    'score' => (float) ($validation['score'] ?? 0.0),
                    'passed' => (bool) ($validation['passed'] ?? false),
                    'validation' => $validation,
                ];
            } catch (\Throwable $exception) {
                // Ảnh đã render: validation fail không hủy candidate — gắn warning + score thấp.
                logger()->warning('Typography validation failed after render, keep candidate', [
                    'render_model' => (string) ($candidate['model_used'] ?? ''),
                    'validation_model' => $validationModel,
                    'error' => $exception->getMessage(),
                ]);

                $scored[] = [
                    'candidate' => $candidate,
                    'score' => 0.0,
                    'passed' => false,
                    'validation' => [
                        'score' => 0.0,
                        'passed' => false,
                        'missing_blocks' => [],
                        'mismatched_blocks' => [],
                        'validation_model' => $validationModel,
                        'error' => $exception->getMessage(),
                    ],
                ];
            }
        }

        $winner = $this->pickWinner($scored, $policy);
        $winnerCandidate = $winner['candidate'];
        $winnerValidation = $winner['validation'];

        $binary = $this->temporaryStorage->read((string) $winnerCandidate['temporary_path']);
        if ($binary === null || $binary === '') {
            throw new PromptRunException('Không đọc được winner candidate.');
        }

        $mime = 'image/png';
        $winnerUrl = $this->promptMediaStorage->storeBinaryMedia(
            binary: $binary,
            mimeType: $mime,
            toolType: 'image_typography',
            aiGenerator: (string) ($winnerCandidate['model_used'] ?? null),
        );

        $loserPaths = [];
        foreach ($validCandidates as $candidate) {
            $path = (string) ($candidate['temporary_path'] ?? '');
            if ($path !== '' && $path !== (string) $winnerCandidate['temporary_path']) {
                $loserPaths[] = $path;
            }
        }
        $this->temporaryStorage->deleteMany($loserPaths);
        $this->temporaryStorage->delete((string) $winnerCandidate['temporary_path']);

        $validationWarning = ! ($winner['passed'] ?? false) && $policy->validationRequired;

        return [
            'url' => $winnerUrl,
            'usage' => is_array($winnerCandidate['usage'] ?? null) ? $winnerCandidate['usage'] : null,
            'model_used' => (string) ($winnerCandidate['model_used'] ?? ''),
            'validation_model' => $validationModel,
            'candidate_count' => count($validCandidates),
            'winner_score' => (float) ($winner['score'] ?? 0.0),
            'validation_passed' => (bool) ($winner['passed'] ?? false),
            'validation_warning' => $validationWarning || $policy->typographyWarning,
            'missing_text_count' => count(is_array($winnerValidation['missing_blocks'] ?? null) ? $winnerValidation['missing_blocks'] : []),
            'mismatched_text_count' => count(is_array($winnerValidation['mismatched_blocks'] ?? null) ? $winnerValidation['mismatched_blocks'] : []),
            'typography_complexity_summary' => $complexity->summary(),
            'metadata' => [
                'execution_policy' => $policy->toArray(),
                'validation_owner' => $policy->validationRequired ? 'system' : 'none',
                'winner_candidate_id' => (string) ($winnerCandidate['candidate_id'] ?? ''),
            ],
        ];
    }

    /**
     * @param  list<array{candidate: array<string, mixed>, score: float, passed: bool, validation: array<string, mixed>|null}>  $scored
     * @return array{candidate: array<string, mixed>, score: float, passed: bool, validation: array<string, mixed>|null}
     */
    private function pickWinner(array $scored, ImageRoutingExecutionPolicy $policy): array
    {
        if ($scored === []) {
            throw new PromptRunException('Không có candidate để chọn winner.');
        }

        $passed = array_values(array_filter($scored, static fn (array $item): bool => (bool) ($item['passed'] ?? false)));
        $pool = $passed !== [] ? $passed : $scored;

        usort($pool, static function (array $a, array $b): int {
            $scoreCmp = ($b['score'] <=> $a['score']);
            if ($scoreCmp !== 0) {
                return $scoreCmp;
            }

            $aMissing = count(is_array($a['validation']['missing_blocks'] ?? null) ? $a['validation']['missing_blocks'] : []);
            $bMissing = count(is_array($b['validation']['missing_blocks'] ?? null) ? $b['validation']['missing_blocks'] : []);
            if ($aMissing !== $bMissing) {
                return $aMissing <=> $bMissing;
            }

            return ((int) ($a['candidate']['attempt'] ?? 0)) <=> ((int) ($b['candidate']['attempt'] ?? 0));
        });

        return $pool[0];
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function isProductImageContext(array $variables): bool
    {
        if (trim((string) ($variables['post_type'] ?? '')) === 'product') {
            return true;
        }

        foreach (['loai_san_pham', 'LOAI_SAN_PHAM', 'gallery_description'] as $key) {
            if (trim((string) ($variables[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }
}
