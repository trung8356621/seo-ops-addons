<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Services;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\Media\Support\ImageModelInputLengthPolicy;
use Omnichannel\Addons\Media\Support\ImageToolType;
use Omnichannel\Addons\AiPrompt\Support\PromptLoaiSanPhamVariable;
use Omnichannel\Addons\Content\Support\Utf8Sanitizer;
use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\Services\PromptRunnerService;

/**
 * Trung chuyển thực thi AI theo công cụ Prompt — tách Imagen/Gemini Image khỏi Claude Text.
 */
final class MediaGenerationService
{
    public function __construct(
        private readonly GeminiMediaGenerationService $geminiMediaGeneration,
        private readonly TypographyPipelineService $typographyPipeline,
    ) {}

    /**
     * Điểm vào chính: phân luồng image vs text (Claude).
     *
     * @param  array<string, string>  $variables
     * @return array{0: string, 1: array<string, mixed>|null}|array{url: string, usage: array<string, mixed>|null, model_used: string}
     */
    public function execute(
        SeoPrompt $prompt,
        array $variables,
        ?string $inputData = null,
        bool $isTaskMode = true,
        ?string $compiledPrompt = null,
        ?string $modelOverride = null,
        string $effectiveToolType = 'default',
    ): array {
        $prompt->loadMissing(['aiConnection']);
        $variables = Utf8Sanitizer::variablesForAi($variables);

        if ($this->shouldUseImagePipeline($prompt, $effectiveToolType)) {
            return $this->executeImagePipeline(
                $prompt,
                $variables,
                $inputData,
                $compiledPrompt,
            );
        }

        $connection = $prompt->aiConnection;
        if ($connection === null || $connection->provider !== 'claude') {
            throw new PromptRunException(
                'Văn bản Gemini chạy qua PromptRunner; MediaGenerationService::execute() chỉ phân luồng ảnh → Imagen hoặc Claude.',
            );
        }

        return app(AiExecutionService::class)->executeClaude(
            $prompt,
            $inputData,
            $isTaskMode,
            $variables,
            $modelOverride,
            $compiledPrompt,
        );
    }

    /**
     * Image pipeline khi bước hiện tại là image / image_typography.
     */
    public function shouldUseImagePipeline(SeoPrompt $prompt, string $effectiveToolType): bool
    {
        return ImageToolType::fromMixed($effectiveToolType)->isImagePipeline();
    }

    public function isPromptImageTool(SeoPrompt $prompt): bool
    {
        return ImageToolType::fromMixed($prompt->tools ?? 'default')->isImagePipeline();
    }

    /**
     * Sinh ảnh một lần (Imagen / Nano Banana) — dùng từ PromptRunner::callProvider.
     *
     * @param  array<string, string>  $variables
     * @return array{url: string, usage: array<string, mixed>|null, model_used: string}
     */
    public function executeImage(
        ApiConnection $connection,
        SeoPrompt $prompt,
        string $compiled,
        array $variables,
        ?array $modelsOverride = null,
    ): array {
        if ($connection->provider !== 'gemini') {
            throw new PromptRunException(
                'Công cụ Hình ảnh cần kết nối Gemini (Imagen 4 hoặc Nano Banana). '
                .'AiExecutionService (Claude) chỉ dùng cho văn bản.',
            );
        }

        $toolType = ImageToolType::fromMixed($prompt->tools ?? 'default');
        if (! $toolType->isImagePipeline()) {
            $toolType = ImageToolType::Image;
        }

        if ($toolType->isTypography()) {
            return $this->typographyPipeline->execute($connection, $prompt, $compiled, $variables);
        }

        $compiledPromptLength = ImageModelInputLengthPolicy::measureCompiledPromptLength($compiled);
        $imagePrompt = $this->buildImageGenerationInput($compiled, $variables, $compiledPromptLength);
        $result = $this->geminiMediaGeneration->generateImage(
            connection: $connection,
            prompt: $imagePrompt,
            toolType: $toolType,
            productContext: $this->isProductImageContext($variables),
            inputLength: $compiledPromptLength,
            modelsOverride: $modelsOverride,
        );

        $firstLine = trim(explode("\n", trim($result['url']), 2)[0] ?? '');
        if (! str_starts_with($firstLine, '/storage/')) {
            throw new PromptRunException(
                'Hình ảnh lỗi: model không trả file ảnh hợp lệ (ImageRoutingStrategy).',
            );
        }

        return $result;
    }

    /**
     * Ảnh sản phẩm (post_type = product / có loai_san_pham, gallery_description):
     * Imagen render chữ trong prompt thành ảnh text → chỉ dùng Nano Banana.
     *
     * @param  array<string, string>  $variables
     */
    private function isProductImageContext(array $variables): bool
    {
        if (trim((string) ($variables['post_type'] ?? '')) === 'product') {
            return true;
        }

        foreach (['loai_san_pham', 'LOAI_SAN_PHAM', 'gallery_description', PromptLoaiSanPhamVariable::CUSTOM_FIELD] as $key) {
            if (trim((string) ($variables[$key] ?? '')) !== '') {
                return true;
            }
        }

        return false;
    }

    /**
     * API cho ImageGenerationChainService / gọi từ bên ngoài khi tools = image*.
     *
     * @param  array<string, string>  $variables
     * @return array<string, mixed>|string
     */
    public function generate(SeoPrompt $prompt, array $variables = [], ?string $inputData = null): array|string
    {
        $prompt->loadMissing(['aiConnection']);
        $variables = Utf8Sanitizer::variablesForAi($variables);

        if ($prompt->aiConnection === null) {
            throw new PromptRunException('Prompt chưa được gắn kết nối AI.');
        }

        if ($inputData !== null && trim($inputData) !== '') {
            $variables['input'] = Utf8Sanitizer::compactForAiVariable($inputData);
        }

        if (app(PromptRunnerService::class)->hasDependentSubTasks($prompt)) {
            return app(ImageGenerationChainService::class)->generateImageChain($prompt, $variables);
        }

        $compiled = app(PromptRunnerService::class)->compilePrompt($prompt, $variables);
        $result = $this->executeImage(
            $prompt->aiConnection,
            $prompt,
            $compiled,
            $variables,
        );

        return $result['url'];
    }

    /**
     * @param  array<string, string>  $variables
     * @return array{url: string, usage: array<string, mixed>|null, model_used: string}
     */
    private function executeImagePipeline(
        SeoPrompt $prompt,
        array $variables,
        ?string $inputData,
        ?string $compiledPrompt,
    ): array {
        $connection = $prompt->aiConnection;
        $variables = Utf8Sanitizer::variablesForAi($variables);
        if ($connection === null) {
            throw new PromptRunException('Prompt chưa được gắn kết nối AI.');
        }

        if ($connection->provider !== 'gemini') {
            throw new PromptRunException(
                'Prompt «Hình ảnh» yêu cầu kết nối Gemini. Không gọi Claude/AiExecutionService cho sinh ảnh.',
            );
        }

        $compiled = trim((string) $compiledPrompt);
        if ($compiled === '') {
            $compiled = app(PromptRunnerService::class)->compilePrompt($prompt, $variables);
        }

        if ($inputData !== null && trim($inputData) !== '') {
            $variables['input'] = Utf8Sanitizer::compactForAiVariable($inputData);
        }

        return $this->executeImage($connection, $prompt, $compiled, $variables);
    }

    /**
     * @param  array<string, string>  $variables
     */
    private function buildImageGenerationInput(string $compiled, array $variables, int $compiledPromptLength): string
    {
        if (ImageModelInputLengthPolicy::shouldTruncateCompiledPrompt($compiledPromptLength)) {
            $compiled = mb_substr(trim($compiled), 0, 8000)
                ."\n\n[Prompt truncated — compiled prompt exceeded "
                .ImageModelInputLengthPolicy::LONG_INPUT_CHARS
                .' characters.]';
        }

        $parent = trim((string) ($variables['PARENT_RESULT'] ?? ''));
        if ($parent !== '' && ! str_starts_with($parent, '/storage/')) {
            $parent = Utf8Sanitizer::string(mb_substr($parent, 0, 1800));

            return Utf8Sanitizer::string(
                "Generate exactly ONE image. Do not output markdown or explanation.\n\n"
                ."Use the following brief from previous step as context:\n"
                .$parent
                ."\n\nRender instructions for this step:\n"
                .$compiled,
            );
        }

        return Utf8Sanitizer::string(
            "Generate exactly ONE image. Do not write instructions, Midjourney prompts, or markdown — output the image.\n\n"
            ."Visual specification:\n\n"
            .$compiled,
        );
    }
}
