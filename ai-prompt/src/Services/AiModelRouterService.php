<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\Exceptions\PromptRunException;
use Omnichannel\Addons\AiPrompt\Exceptions\AiRoutingException;
use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\DataTransfer\RoutedAiCandidate;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Models\SeoPrompt;
use Omnichannel\Addons\AiPrompt\Services\Ai\DeepSeekChatClient;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\Seo\Support\AiModelCategory;
use Omnichannel\Addons\Seo\Support\GeminiModelVersionPolicy;
use Omnichannel\Addons\Seo\Support\GoogleAiModelRegistry;
use Omnichannel\Addons\Media\Support\ImageCapability;
use Omnichannel\Addons\Media\Support\ImageCapabilityResolver;
use Omnichannel\Addons\Media\Support\ImageToolType;
use Omnichannel\Addons\Seo\Support\RenderingPreference;
use Omnichannel\Addons\Seo\Services\SeoCreateArticleSettingsService;
use Omnichannel\Addons\Content\Support\SystemDateTime;
use App\Models\ApiConnection;
use Illuminate\Support\Facades\Http;
use Throwable;

final class AiModelRouterService
{
    private const MAX_FAILOVER_ATTEMPTS = 8;

    public function __construct(
        private readonly ModelCapabilityRegistry $capabilityRegistry = new ModelCapabilityRegistry(),
        private readonly ?AiRoutingTargetService $routingTargetService = null,
        private readonly ?AiRoutingBootstrapService $routingBootstrapService = null,
        private readonly ?DeepSeekChatClient $deepSeekClient = null,
    ) {}

    /**
     * Canonical profile → candidate resolution. Prompt/Automation must use this.
     */
    public function resolve(string $profile, AiRoutingContext $context): RoutedAiCandidate
    {
        $candidates = $this->resolveAll($profile, $context);
        if ($candidates === []) {
            $parsed = AiExecutionProfile::tryFrom($profile);
            $capability = $parsed?->requiredCapabilityKeys()[0] ?? 'text.generate';
            throw AiRoutingException::noCandidate($profile, $capability);
        }

        return $candidates[0];
    }

    /**
     * @return list<RoutedAiCandidate>
     */
    public function resolveAll(string $profile, AiRoutingContext $context): array
    {
        $parsed = AiExecutionProfile::tryFrom($profile);
        if ($parsed === null) {
            throw new AiRoutingException('Unknown routing profile: '.$profile);
        }

        $userId = $context->userId ?? (int) (auth()->id() ?? 0);
        $targets = $this->targetsService();
        $bootstrap = $this->bootstrapService();

        if ($userId > 0 && $targets !== null && $bootstrap !== null) {
            if ($targets->targetsFor($userId, $parsed->value) === []) {
                $bootstrap->bootstrapForUser($userId);
            }
            $candidates = $targets->eligibleCandidates($userId, $parsed, $context);
            if ($candidates !== []) {
                return $candidates;
            }
        }

        if ($context->allowLegacyFallback && $context->legacyConnection instanceof ApiConnection) {
            $legacy = $this->legacyCompatibleCandidate($parsed, $context->legacyConnection);
            if ($legacy !== null) {
                return [$legacy];
            }
        }

        return [];
    }

    /**
     * Infrastructure fallback across profile candidates. Does not retry on "quality".
     *
     * @param  callable(RoutedAiCandidate): array{0: string, 1: array<string, mixed>|null}  $executor
     * @return array{0: string, 1: array<string, mixed>|null, 2: RoutedAiCandidate, 3: int, 4: list<string>}
     */
    public function executeWithProfile(
        string $profile,
        AiRoutingContext $context,
        callable $executor,
    ): array {
        $candidates = $this->resolveAll($profile, $context);
        if ($candidates === []) {
            $parsed = AiExecutionProfile::tryFrom($profile);
            $capability = $parsed?->requiredCapabilityKeys()[0] ?? 'text.generate';
            throw AiRoutingException::noCandidate($profile, $capability);
        }

        $fallbackCount = 0;
        $reasons = [];
        $lastException = null;

        foreach ($candidates as $index => $candidate) {
            try {
                [$output, $usage] = $executor($candidate);

                return [$output, $usage, $candidate, $fallbackCount, $reasons];
            } catch (\Throwable $exception) {
                $lastException = $exception;
                if (! $this->isInfrastructureFailure($exception->getMessage())) {
                    throw $exception instanceof PromptRunException
                        ? $exception
                        : new PromptRunException($exception->getMessage(), (int) $exception->getCode(), $exception);
                }

                $fallbackCount++;
                $reasons[] = 'priority '.$candidate->priority.' '.$candidate->provider.'/'.$candidate->model.': '.$exception->getMessage();
                logger()->warning('AI routing infrastructure fallback', [
                    'profile' => $profile,
                    'priority' => $candidate->priority,
                    'provider' => $candidate->provider,
                    'model' => $candidate->model,
                    'error' => $exception->getMessage(),
                    'next' => isset($candidates[$index + 1]),
                ]);

                if ($candidate->seoAiModelId !== null) {
                    if ($this->isQuotaOrRateLimitError($exception->getMessage())) {
                        $this->handleModelExhausted($candidate->seoAiModelId, $exception->getMessage());
                    } elseif (GeminiModelVersionPolicy::isProviderUnavailableError($exception->getMessage())) {
                        $this->markModelUnavailableForAutoRouting($candidate->seoAiModelId, $exception->getMessage());
                    }
                }
            }
        }

        throw $lastException instanceof PromptRunException
            ? $lastException
            : new PromptRunException(
                $lastException?->getMessage() ?? 'AI routing exhausted all candidates for profile '.$profile,
            );
    }

    public function isInfrastructureFailure(string $message): bool
    {
        $lower = strtolower($message);

        return $this->isQuotaOrRateLimitError($message)
            || GeminiModelVersionPolicy::isProviderUnavailableError($message)
            || str_contains($message, '429')
            || str_contains($message, '500')
            || str_contains($message, '502')
            || str_contains($message, '503')
            || str_contains($message, '504')
            || str_contains($lower, 'timeout')
            || str_contains($lower, 'timed out')
            || str_contains($lower, 'connection')
            || str_contains($lower, 'temporarily')
            || str_contains($lower, 'unavailable')
            || str_contains($lower, 'overloaded');
    }

    private function legacyCompatibleCandidate(AiExecutionProfile $profile, ApiConnection $connection): ?RoutedAiCandidate
    {
        if ((string) $connection->status !== 'active' || blank($connection->api_key)) {
            return null;
        }

        $models = SeoAiModel::query()
            ->where('api_connection_id', $connection->id)
            ->where('status', SeoAiModel::STATUS_ACTIVE)
            ->orderByDesc('priority')
            ->get();

        foreach ($models as $model) {
            $key = (string) $model->raw_model_name;
            if (! $this->capabilityRegistry->satisfiesAll($connection, $key, $profile->requiredCapabilityKeys())) {
                continue;
            }

            return new RoutedAiCandidate(
                profile: $profile->value,
                connection: $connection,
                provider: (string) $connection->provider,
                model: $key,
                capabilities: $this->capabilityRegistry->capabilitiesFor($connection, $key),
                priority: 99,
                seoAiModelId: (int) $model->id,
                legacyFallback: true,
            );
        }

        return null;
    }

    private function targetsService(): ?AiRoutingTargetService
    {
        if ($this->routingTargetService instanceof AiRoutingTargetService) {
            return $this->routingTargetService;
        }

        return function_exists('app') ? app(AiRoutingTargetService::class) : null;
    }

    private function bootstrapService(): ?AiRoutingBootstrapService
    {
        if ($this->routingBootstrapService instanceof AiRoutingBootstrapService) {
            return $this->routingBootstrapService;
        }

        return function_exists('app') ? app(AiRoutingBootstrapService::class) : null;
    }

    /**
     * Đồng bộ model từ Google Generative Language API.
     */
    public function syncGeminiModels(int $connectionId): bool
    {
        $connection = ApiConnection::query()->find($connectionId);
        if ($connection === null || $connection->provider !== 'gemini') {
            return false;
        }

        if (blank($connection->api_key)) {
            return false;
        }

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->withQueryParameters(['key' => $connection->api_key])
                ->get(app(\Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\ProviderConnectionResolver::class)
                    ->httpBaseUrl($connection).'/v1beta/models');

            $seenRaw = [];

            if ($response->successful()) {
                $models = $response->json('models', []);
                if (is_array($models)) {
                    foreach ($models as $model) {
                        if (! is_array($model)) {
                            continue;
                        }

                        $rawName = str_replace('models/', '', (string) ($model['name'] ?? ''));
                        if ($rawName === '') {
                            continue;
                        }

                        $classified = $this->classifyGeminiModel(
                            $rawName,
                            (array) ($model['supportedGenerationMethods'] ?? []),
                        );

                        if ($classified === null) {
                            continue;
                        }

                        $seenRaw[] = $rawName;

                        SeoAiModel::query()->updateOrCreate(
                            [
                                'api_connection_id' => $connectionId,
                                'raw_model_name' => $rawName,
                            ],
                            $this->mergeSyncPayload($connectionId, $rawName, [
                                'category' => $classified['category'],
                                'display_name' => (string) ($model['displayName'] ?? $rawName),
                                'priority' => $classified['priority'],
                                'status' => SeoAiModel::STATUS_ACTIVE,
                                'capabilities' => $this->capabilitiesWithResolved($rawName, [
                                    'supportedGenerationMethods' => $model['supportedGenerationMethods'] ?? [],
                                ]),
                                'last_error' => null,
                            ]),
                        );
                    }
                }
            } else {
                logger()->warning('syncGeminiModels API list failed', [
                    'connection_id' => $connectionId,
                    'status' => $response->status(),
                    'body' => $response->body(),
                ]);
            }

            $seenRaw = array_values(array_unique(array_merge(
                $seenRaw,
                $this->seedGeminiCatalogModels($connectionId),
            )));

            if ($seenRaw !== []) {
                $this->deactivateMissingModels($connectionId, $seenRaw);

                return true;
            }

            return false;
        } catch (Throwable $exception) {
            logger()->error('syncGeminiModels failed', [
                'connection_id' => $connectionId,
                'message' => $exception->getMessage(),
            ]);

            $seenRaw = $this->seedGeminiCatalogModels($connectionId);
            if ($seenRaw !== []) {
                $this->deactivateMissingModels($connectionId, $seenRaw);

                return true;
            }

            return false;
        }
    }

    /**
     * Đồng bộ model Claude từ Anthropic API.
     */
    public function syncClaudeModels(int $connectionId): bool
    {
        $connection = ApiConnection::query()->find($connectionId);
        if ($connection === null || $connection->provider !== 'claude') {
            return false;
        }

        if (blank($connection->api_key)) {
            return false;
        }

        try {
            $response = Http::timeout(30)
                ->acceptJson()
                ->withHeaders([
                    'x-api-key' => $connection->api_key,
                    'anthropic-version' => '2023-06-01',
                ])
                ->get('https://api.anthropic.com/v1/models');

            if (! $response->successful()) {
                return $this->seedClaudeFallbackModels($connectionId);
            }

            $models = $response->json('data', []);
            if (! is_array($models) || $models === []) {
                return $this->seedClaudeFallbackModels($connectionId);
            }

            $seenRaw = [];

            foreach ($models as $model) {
                if (! is_array($model)) {
                    continue;
                }

                $rawName = (string) ($model['id'] ?? $model['name'] ?? '');
                if ($rawName === '') {
                    continue;
                }

                $classified = $this->classifyClaudeModel($rawName);
                if ($classified === null) {
                    continue;
                }

                $seenRaw[] = $rawName;

                SeoAiModel::query()->updateOrCreate(
                    [
                        'api_connection_id' => $connectionId,
                        'raw_model_name' => $rawName,
                    ],
                    [
                        'category' => $classified['category'],
                        'display_name' => (string) ($model['display_name'] ?? $model['displayName'] ?? $rawName),
                        'priority' => $classified['priority'],
                        'status' => SeoAiModel::STATUS_ACTIVE,
                        'capabilities' => $this->capabilitiesWithResolved($rawName, ['source' => 'anthropic']),
                        'last_error' => null,
                    ],
                );
            }

            $this->deactivateMissingModels($connectionId, $seenRaw);

            return true;
        } catch (Throwable $exception) {
            logger()->error('syncClaudeModels failed', [
                'connection_id' => $connectionId,
                'message' => $exception->getMessage(),
            ]);

            return $this->seedClaudeFallbackModels($connectionId);
        }
    }

    public function syncModelsForConnection(int $connectionId): bool
    {
        $connection = ApiConnection::query()->find($connectionId);
        if ($connection === null) {
            return false;
        }

        return match ($connection->provider) {
            'gemini' => $this->syncGeminiModels($connectionId),
            'claude' => $this->syncClaudeModels($connectionId),
            ApiConnectionProviders::DEEPSEEK => $this->syncDeepSeekModels($connectionId),
            ApiConnectionProviders::OPENROUTER => $this->syncOpenAiCompatibleModels($connectionId),
            default => false,
        };
    }

    public function getActiveModel(int $connectionId, string $category): ?SeoAiModel
    {
        return SeoAiModel::query()
            ->where('api_connection_id', $connectionId)
            ->where('category', $category)
            ->where('status', SeoAiModel::STATUS_ACTIVE)
            ->orderByDesc('priority')
            ->orderByDesc('id')
            ->first();
    }

    public function handleModelExhausted(int $modelId, string $errorMessage): void
    {
        $failedModel = SeoAiModel::query()->find($modelId);
        if ($failedModel === null) {
            return;
        }

        $failedModel->update([
            'status' => SeoAiModel::STATUS_EXHAUSTED,
            'last_error' => mb_substr($errorMessage, 0, 2000),
        ]);

        logger()->warning('AI model exhausted, failover next', [
            'planner_model' => $failedModel->raw_model_name,
            'category' => $failedModel->category,
        ]);
    }

    public function markModelUnavailableForAutoRouting(int $modelId, string $errorMessage): void
    {
        $failedModel = SeoAiModel::query()->find($modelId);
        if ($failedModel === null) {
            return;
        }

        $capabilities = is_array($failedModel->capabilities) ? $failedModel->capabilities : [];
        $failedModel->update([
            'capabilities' => GeminiModelVersionPolicy::markCapabilitiesUnavailable($capabilities, $errorMessage),
            'last_error' => mb_substr($errorMessage, 0, 2000),
        ]);

        logger()->warning('AI model marked unavailable for auto-routing', [
            'planner_model' => $failedModel->raw_model_name,
            'disabled_reason' => GeminiModelVersionPolicy::REASON_PROVIDER_UNAVAILABLE,
        ]);
    }

    /**
     * Category cho text path theo RenderingPreference.
     * Image path không dùng — ImageRoutingStrategy.
     *
     * @deprecated prompts.model_category không còn điều khiển routing
     */
    public function resolveCategoryForPrompt(SeoPrompt $prompt, string $toolType = 'default'): string
    {
        $connection = $prompt->aiConnection;
        $provider = $connection !== null ? (string) $connection->provider : 'gemini';

        if (ImageToolType::fromMixed($toolType)->isImagePipeline()) {
            return AiModelCategory::IMAGEN_PRO;
        }

        $preference = app(SeoCreateArticleSettingsService::class)->getRenderingPreference();

        return match ($provider) {
            'claude' => match ($preference) {
                RenderingPreference::CostFirst => AiModelCategory::CLAUDE_HAIKU,
                RenderingPreference::QualityFirst => AiModelCategory::CLAUDE_OPUS,
                RenderingPreference::Balanced => AiModelCategory::CLAUDE_SONNET,
            },
            ApiConnectionProviders::DEEPSEEK => AiModelCategory::DEEPSEEK_CHAT,
            default => match ($preference) {
                RenderingPreference::QualityFirst => AiModelCategory::GEMINI_PRO,
                RenderingPreference::CostFirst,
                RenderingPreference::Balanced => AiModelCategory::GEMINI_FLASH,
            },
        };
    }

    /**
     * Thực thi callable với failover theo category.
     *
     * @param  callable(string $rawModelName, ?int $seoAiModelId): array{0: string, 1: array<string, mixed>|null}  $executor
     * @return array{0: string, 1: array<string, mixed>|null, 2: string, 3: ?int}
     */
    public function executeWithFailover(
        ApiConnection $connection,
        string $category,
        callable $executor,
        int $attempt = 0,
        ?int $excludeModelId = null,
    ): array {
        if ($attempt >= self::MAX_FAILOVER_ATTEMPTS) {
            throw new PromptRunException('Đã thử toàn bộ model dự phòng trong nhóm «'.$category.'» nhưng đều thất bại.');
        }

        if (! AiModelCategory::matchesProvider($category, (string) $connection->provider)) {
            throw new PromptRunException(
                'Nhóm model «'.$category.'» không tương thích với kết nối '.$connection->provider.'.',
            );
        }

        $activeModel = $this->getNextActiveModel((int) $connection->id, $category, $excludeModelId);

        if ($activeModel !== null) {
            $rawName = (string) $activeModel->raw_model_name;
            $modelId = (int) $activeModel->id;

            try {
                [$output, $usage] = $executor($rawName, $modelId);

                return [$output, $usage, $rawName, $modelId];
            } catch (Throwable $exception) {
                if ($this->isQuotaOrRateLimitError($exception->getMessage())
                    || GeminiModelVersionPolicy::isProviderUnavailableError($exception->getMessage())
                ) {
                    if (GeminiModelVersionPolicy::isProviderUnavailableError($exception->getMessage())) {
                        $this->markModelUnavailableForAutoRouting($modelId, $exception->getMessage());
                    } else {
                        $this->handleModelExhausted($modelId, $exception->getMessage());
                    }

                    logger()->warning('Planner model failed, failover next', [
                        'planner_model' => $rawName,
                        'error' => $exception->getMessage(),
                    ]);

                    return $this->executeWithFailover($connection, $category, $executor, $attempt + 1, $modelId);
                }

                throw $exception instanceof PromptRunException
                    ? $exception
                    : new PromptRunException($exception->getMessage(), (int) $exception->getCode(), $exception);
            }
        }

        $fallbackRaw = $this->fallbackRawModelName($connection, $category);
        if ($fallbackRaw === '') {
            throw new PromptRunException(
                'Không có model active trong DB cho nhóm «'.$category.'». Vào Cấu hình AI → Đồng bộ model.',
            );
        }

        [$output, $usage] = $executor($fallbackRaw, null);

        return [$output, $usage, $fallbackRaw, null];
    }

    public function isQuotaOrRateLimitError(string $message): bool
    {
        $lower = strtolower($message);

        return str_contains($message, '429')
            || str_contains($lower, 'resource exhausted')
            || str_contains($lower, 'resource_exhausted')
            || str_contains($lower, 'quota')
            || str_contains($lower, 'rate limit')
            || str_contains($lower, 'rate_limit')
            || str_contains($lower, 'overloaded')
            || str_contains($lower, 'too many requests')
            || str_contains($lower, 'insufficient')
            || str_contains($lower, 'billing');
    }

    /**
     * @param  list<string>  $methods
     * @return array{category: string, priority: int}|null
     */
    private function classifyGeminiModel(string $rawName, array $methods): ?array
    {
        $lower = strtolower($rawName);
        $methods = array_map('strtolower', $methods);

        if (str_contains($lower, 'embedding') || str_contains($lower, 'tts') || str_contains($lower, 'veo')
            || str_contains($lower, 'lyria') || str_contains($lower, 'computer-use') || str_contains($lower, 'robotics')) {
            return null;
        }

        if (str_contains($lower, 'imagen') || in_array('predict', $methods, true)) {
            $priority = 100;
            if (str_contains($lower, 'ultra')) {
                $priority = 220;
            } elseif (str_contains($lower, 'fast')) {
                $priority = 200;
            } elseif (preg_match('/imagen-4/', $lower)) {
                $priority = 210;
            } elseif (str_contains($lower, '3.0')) {
                $priority = 150;
            }

            return ['category' => AiModelCategory::IMAGEN_PRO, 'priority' => $priority];
        }

        if (str_contains($lower, 'image') || str_contains($lower, 'banana')) {
            $priority = $this->versionPriority($lower, 180);

            return ['category' => AiModelCategory::IMAGEN_PRO, 'priority' => $priority];
        }

        if (! in_array('generatecontent', $methods, true) && $methods !== []) {
            return null;
        }

        if (str_contains($lower, 'pro') && ! str_contains($lower, 'flash')) {
            return ['category' => AiModelCategory::GEMINI_PRO, 'priority' => $this->versionPriority($lower, 120)];
        }

        if (str_contains($lower, 'flash') || str_contains($lower, 'lite')) {
            return ['category' => AiModelCategory::GEMINI_FLASH, 'priority' => $this->versionPriority($lower, 100)];
        }

        if (preg_match('/gemini-[\d.]+/', $lower)) {
            return ['category' => AiModelCategory::GEMINI_FLASH, 'priority' => 80];
        }

        return null;
    }

    /**
     * @return array{category: string, priority: int}|null
     */
    private function classifyClaudeModel(string $rawName): ?array
    {
        $lower = strtolower($rawName);

        if (str_contains($lower, 'opus')) {
            return ['category' => AiModelCategory::CLAUDE_OPUS, 'priority' => $this->versionPriority($lower, 200)];
        }

        if (str_contains($lower, 'sonnet')) {
            return ['category' => AiModelCategory::CLAUDE_SONNET, 'priority' => $this->versionPriority($lower, 150)];
        }

        if (str_contains($lower, 'haiku')) {
            return ['category' => AiModelCategory::CLAUDE_HAIKU, 'priority' => $this->versionPriority($lower, 100)];
        }

        return null;
    }

    private function versionPriority(string $rawName, int $base): int
    {
        if (preg_match('/3\.5|3-5/', $rawName)) {
            return $base + 80;
        }

        if (preg_match('/3\.1|3-1/', $rawName)) {
            return $base + 70;
        }

        if (preg_match('/3\.0|3-0|\b3-pro|\b3-flash/', $rawName)) {
            return $base + 60;
        }

        if (preg_match('/2\.5|2-5/', $rawName)) {
            return $base + 50;
        }

        if (preg_match('/2\.0|2-0/', $rawName)) {
            return $base + 40;
        }

        if (preg_match('/1\.5|1-5/', $rawName)) {
            return $base + 20;
        }

        return $base;
    }

    private function getNextActiveModel(int $connectionId, string $category, ?int $excludeModelId): ?SeoAiModel
    {
        $query = SeoAiModel::query()
            ->where('api_connection_id', $connectionId)
            ->where('category', $category)
            ->where('status', SeoAiModel::STATUS_ACTIVE)
            ->orderByDesc('priority')
            ->orderByDesc('id');

        if ($excludeModelId !== null) {
            $query->where('id', '!=', $excludeModelId);
        }

        foreach ($query->get() as $model) {
            $capabilities = is_array($model->capabilities) ? $model->capabilities : [];
            if (GeminiModelVersionPolicy::isEligibleForAutoRouting((string) $model->raw_model_name, $capabilities)) {
                return $model;
            }
        }

        return null;
    }

    private function fallbackRawModelName(ApiConnection $connection, string $category): string
    {
        $legacyRaw = trim((string) ($connection->default_model ?? ''));
        if (
            $legacyRaw !== ''
            && ! AiModelCategory::isValid($legacyRaw)
            && $this->categoryForLegacyRaw($legacyRaw) === $category
            && GeminiModelVersionPolicy::isEligibleForAutoRouting($legacyRaw)
        ) {
            return $legacyRaw;
        }

        return match ($category) {
            AiModelCategory::IMAGEN_PRO => 'gemini-3.1-flash-image-preview',
            AiModelCategory::GEMINI_PRO => 'gemini-3.1-pro-preview',
            AiModelCategory::GEMINI_FLASH => 'gemini-3-flash-preview',
            AiModelCategory::CLAUDE_OPUS => 'claude-opus-4-20250514',
            AiModelCategory::CLAUDE_SONNET => 'claude-sonnet-4-20250514',
            AiModelCategory::CLAUDE_HAIKU => 'claude-3-5-haiku-20241022',
            AiModelCategory::DEEPSEEK_CHAT => 'deepseek-chat',
            AiModelCategory::DEEPSEEK_REASONER => 'deepseek-reasoner',
            default => '',
        };
    }

    private function categoryForLegacyRaw(string $raw): string
    {
        if (GoogleAiModelRegistry::isImagenModel($raw) || GoogleAiModelRegistry::isGeminiNativeImageModel($raw)) {
            return AiModelCategory::IMAGEN_PRO;
        }

        $lower = strtolower($raw);
        if (str_contains($lower, 'opus')) {
            return AiModelCategory::CLAUDE_OPUS;
        }

        if (str_contains($lower, 'haiku')) {
            return AiModelCategory::CLAUDE_HAIKU;
        }

        if (str_contains($lower, 'sonnet')) {
            return AiModelCategory::CLAUDE_SONNET;
        }

        if (str_contains($lower, 'pro')) {
            return AiModelCategory::GEMINI_PRO;
        }

        return AiModelCategory::GEMINI_FLASH;
    }

    /**
     * @param  list<string>  $activeRawNames
     */
    private function deactivateMissingModels(int $connectionId, array $activeRawNames): void
    {
        if ($activeRawNames === []) {
            return;
        }

        SeoAiModel::query()
            ->where('api_connection_id', $connectionId)
            ->whereNotIn('raw_model_name', $activeRawNames)
            ->where('status', SeoAiModel::STATUS_ACTIVE)
            ->update(['status' => SeoAiModel::STATUS_INACTIVE]);
    }

    /**
     * Imagen / Nano Banana thường không có trong GET /models — luôn seed từ catalog nội bộ.
     *
     * @return list<string>
     */
    private function seedGeminiCatalogModels(int $connectionId): array
    {
        $catalog = [
            ['imagen-4.0-fast-generate-001', 'Imagen 4 Fast Generate', AiModelCategory::IMAGEN_PRO, 230, ['predict']],
            ['imagen-4.0-generate-001', 'Imagen 4 Generate', AiModelCategory::IMAGEN_PRO, 220, ['predict']],
            ['imagen-4.0-ultra-generate-001', 'Imagen 4 Ultra Generate', AiModelCategory::IMAGEN_PRO, 225, ['predict']],
            ['gemini-3.1-flash-image-preview', 'Nano Banana 2 (Gemini 3.1 Flash Image)', AiModelCategory::IMAGEN_PRO, 210, ['generateContent']],
            ['gemini-3-pro-image-preview', 'Nano Banana Pro (Gemini 3 Pro Image)', AiModelCategory::IMAGEN_PRO, 205, ['generateContent']],
            ['gemini-2.5-flash-image', 'Nano Banana (Gemini 2.5 Flash Image)', AiModelCategory::IMAGEN_PRO, 190, ['generateContent']],
            ['gemini-2.5-pro-image', 'Nano Banana Pro (Gemini 2.5 Pro Image)', AiModelCategory::IMAGEN_PRO, 188, ['generateContent']],
            ['gemini-3.1-pro-preview', 'Gemini 3.1 Pro', AiModelCategory::GEMINI_PRO, 200, ['generateContent']],
            ['gemini-3-flash-preview', 'Gemini 3 Flash', AiModelCategory::GEMINI_FLASH, 200, ['generateContent']],
            ['gemini-3.5-flash-preview', 'Gemini 3.5 Flash', AiModelCategory::GEMINI_FLASH, 195, ['generateContent']],
            ['gemini-2.5-flash', 'Gemini 2.5 Flash', AiModelCategory::GEMINI_FLASH, 180, ['generateContent']],
            ['gemini-2.5-pro', 'Gemini 2.5 Pro', AiModelCategory::GEMINI_PRO, 180, ['generateContent']],
            ['gemini-2.0-flash', 'Gemini 2.0 Flash', AiModelCategory::GEMINI_FLASH, 150, ['generateContent']],
        ];

        $seeded = [];

        foreach ($catalog as [$raw, $label, $category, $priority, $methods]) {
            SeoAiModel::query()->updateOrCreate(
                [
                    'api_connection_id' => $connectionId,
                    'raw_model_name' => $raw,
                ],
                $this->mergeSyncPayload($connectionId, $raw, [
                    'category' => $category,
                    'display_name' => $label,
                    'priority' => $priority,
                    'status' => SeoAiModel::STATUS_ACTIVE,
                    'capabilities' => $this->capabilitiesWithResolved($raw, [
                        'supportedGenerationMethods' => $methods,
                        'source' => 'catalog',
                    ]),
                    'last_error' => null,
                ]),
            );

            $seeded[] = $raw;
        }

        return $seeded;
    }

    /**
     * @param  array<string, mixed>  $base
     * @return array<string, mixed>
     */
    private function capabilitiesWithResolved(string $rawName, array $base): array
    {
        return (new ImageCapabilityResolver())->mergeResolvedIntoCapabilities($rawName, $base);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function mergeSyncPayload(int $connectionId, string $rawName, array $payload): array
    {
        $existing = SeoAiModel::query()
            ->where('api_connection_id', $connectionId)
            ->where('raw_model_name', $rawName)
            ->first();
        if (! $existing instanceof SeoAiModel) {
            return $payload;
        }
        $payload['priority'] = (int) ($existing->priority ?: ($payload['priority'] ?? 100));
        $incoming = is_array($payload['capabilities'] ?? null) ? $payload['capabilities'] : [];
        $payload['capabilities'] = (new AiModelPriorityService())->copyAreas(
            is_array($existing->capabilities) ? $existing->capabilities : [],
            $incoming,
        );
        if (\Illuminate\Support\Facades\Schema::hasColumn('seo_ai_models', 'is_hidden')
            && ! array_key_exists('is_hidden', $payload)) {
            $payload['is_hidden'] = (bool) ($existing->getAttribute('is_hidden') ?? false);
        }

        return $payload;
    }

    /**
     * @return array{connections: list<array<string, mixed>>, total_models: int, last_synced_at: ?string}
     */
    public function overviewForUser(?int $userId = null): array
    {
        $userId ??= (int) auth()->id();

        $connections = ApiConnection::query()
            ->with(['seoAiModels' => static fn ($query) => $query
                ->orderByDesc('priority')
                ->orderBy('category')
                ->orderBy('raw_model_name')])
            ->where(function ($query) use ($userId): void {
                $query->where('user_id', $userId)
                    ->orWhere('is_global', true);
            })
            ->orderBy('name')
            ->get();

        $rows = [];
        $total = 0;
        $latestSync = null;

        $resolver = new ImageCapabilityResolver();
        $labels = new \Omnichannel\Addons\AiPrompt\Support\AiModelLabelPresenter();
        $catalog = new AiModelFamilyCatalog();
        $adminEnabledUnknown = array_fill_keys(
            app(SeoCreateArticleSettingsService::class)->getAdminEnabledUnknownImageModels(),
            true,
        );

        foreach ($connections as $connection) {
            $models = [];
            $groups = [
                'text' => [],
                'image' => [],
                'image_typography' => [],
                'video' => [],
                'unknown' => [],
            ];

            foreach ($connection->seoAiModels as $model) {
                $total++;
                $capabilities = is_array($model->capabilities) ? $model->capabilities : [];
                $resolved = $resolver->resolve((string) $model->raw_model_name, $capabilities);
                $group = ImageCapability::displayGroupForCapabilities($resolved);
                $slug = GoogleAiModelRegistry::normalizeSlug((string) $model->raw_model_name);
                $routing = GeminiModelVersionPolicy::routingDecision($slug, $capabilities);
                $family = $catalog->familyForModelId((string) $model->raw_model_name);
                $showNormal = $family !== null
                    && $model->status === SeoAiModel::STATUS_ACTIVE
                    && ($routing['routing_status'] ?? '') !== 'disabled'
                    && $group !== 'unknown';
                $row = [
                    'id' => $model->id,
                    'category' => $model->category,
                    'category_label' => AiModelCategory::promptSelectOptions()[$model->category] ?? $model->category,
                    'capability_group' => $group,
                    'capabilities_resolved' => $resolved,
                    'raw_model_name' => $model->raw_model_name,
                    'display_name' => $labels->normal((string) $model->raw_model_name, (string) $model->display_name),
                    'family_key' => $family?->familyKey,
                    'show_in_normal' => $showNormal,
                    'priority' => $model->priority,
                    'status' => $model->status,
                    'routing_status' => $routing['routing_status'],
                    'disabled_reason' => $routing['disabled_reason'],
                    'last_error' => $model->last_error,
                    'admin_enabled_unknown' => isset($adminEnabledUnknown[$slug]),
                    'updated_at' => $model->updated_at?->timezone(config('app.timezone'))->format('d/m/Y H:i'),
                ];
                $models[] = $row;
                $groups[$group][] = $row;

                if ($model->updated_at !== null
                    && ($latestSync === null || $model->updated_at->gt($latestSync))) {
                    $latestSync = $model->updated_at;
                }
            }

            $rows[] = [
                'id' => $connection->id,
                'name' => $connection->name,
                'provider' => $connection->provider,
                'status' => $connection->status,
                'model_count' => count($models),
                'models' => $models,
                'groups' => $groups,
            ];
        }

        return [
            'connections' => $rows,
            'total_models' => $total,
            'last_synced_at' => $latestSync !== null ? SystemDateTime::formatDateTime($latestSync) : null,
            'capability_groups' => ImageCapability::displayGroups(),
        ];
    }

    public function toggleAdminEnabledUnknownImageModel(string $rawModelName, bool $enabled): void
    {
        $settings = app(SeoCreateArticleSettingsService::class);
        $slug = GoogleAiModelRegistry::normalizeSlug($rawModelName);
        if ($slug === '') {
            return;
        }

        $current = $settings->getAdminEnabledUnknownImageModels();
        if ($enabled) {
            $current[] = $slug;
        } else {
            $current = array_values(array_filter($current, static fn (string $item): bool => $item !== $slug));
        }

        $bag = $settings->getSettings();
        $bag[SeoCreateArticleSettingsService::KEY_ADMIN_ENABLED_UNKNOWN_IMAGE_MODELS] = array_values(array_unique($current));
        $settings->saveSettings($bag);
    }

    /**
     * @return array{ok: int, failed: int, messages: list<string>}
     */
    public function syncAllConnectionsForUser(?int $userId = null): array
    {
        $userId ??= (int) auth()->id();

        $connections = ApiConnection::query()
            ->where(function ($query) use ($userId): void {
                $query->where('user_id', $userId)
                    ->orWhere('is_global', true);
            })
            ->where('status', 'active')
            ->get();

        $ok = 0;
        $failed = 0;
        $messages = [];

        foreach ($connections as $connection) {
            if (blank($connection->api_key)) {
                $failed++;
                $messages[] = $connection->name.': thiếu API Key';

                continue;
            }

            if ($this->syncModelsForConnection((int) $connection->id)) {
                $ok++;
            } else {
                $failed++;
                $messages[] = $connection->name.': đồng bộ thất bại (kiểm tra log)';
            }
        }

        return ['ok' => $ok, 'failed' => $failed, 'messages' => $messages];
    }

    public function syncOpenAiCompatibleModels(int $connectionId): bool
    {
        $connection = ApiConnection::query()->find($connectionId);
        if ($connection === null) {
            return false;
        }
        $provider = (string) $connection->provider;
        if (! in_array($provider, [ApiConnectionProviders::OPENROUTER, ApiConnectionProviders::DEEPSEEK], true)
            && $provider !== 'openai_compatible') {
            return false;
        }
        if (blank($connection->api_key)) {
            return false;
        }

        try {
            $adapter = function_exists('app')
                ? app(\Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\OpenAiCompatibleProtocolAdapter::class)
                : new \Omnichannel\Addons\AiPrompt\Services\ProviderTemplates\OpenAiCompatibleProtocolAdapter();
            $catalog = new AiModelFamilyCatalog();
            $seenRaw = [];
            foreach ($adapter->listModels($connection) as $row) {
                $rawName = (string) ($row['id'] ?? '');
                if ($rawName === '') {
                    continue;
                }
                $seenRaw[] = $rawName;
                $existing = SeoAiModel::query()
                    ->where('api_connection_id', $connectionId)
                    ->where('raw_model_name', $rawName)
                    ->first();
                $family = $catalog->familyForModelId($rawName);
                $hidden = $existing instanceof SeoAiModel
                    ? (bool) ($existing->getAttribute('is_hidden') ?? false)
                    : true;
                $category = $family !== null
                    ? ($family->modality === 'image' ? AiModelCategory::IMAGEN_PRO : AiModelCategory::GEMINI_FLASH)
                    : AiModelCategory::GEMINI_FLASH;
                if (str_contains(strtolower($rawName), 'reason')) {
                    $category = AiModelCategory::DEEPSEEK_REASONER;
                }
                $payload = $this->mergeSyncPayload($connectionId, $rawName, [
                    'category' => $existing?->category ?: $category,
                    'display_name' => (string) ($row['display_name'] ?? $rawName),
                    'priority' => $existing?->priority ?: 100,
                    'status' => $existing?->status ?: SeoAiModel::STATUS_ACTIVE,
                    'capabilities' => [
                        'source' => $provider,
                        'language_suitability' => 'unknown',
                        'provider_metadata' => is_array($row['metadata'] ?? null) ? $row['metadata'] : [],
                        'resolved' => $this->capabilityRegistry->capabilitiesFor($connection, $rawName),
                    ],
                    'last_error' => null,
                ]);
                if (\Illuminate\Support\Facades\Schema::hasColumn('seo_ai_models', 'is_hidden')) {
                    $payload['is_hidden'] = $hidden;
                }
                SeoAiModel::query()->updateOrCreate(
                    [
                        'api_connection_id' => $connectionId,
                        'raw_model_name' => $rawName,
                    ],
                    $payload,
                );
            }
            $seenRaw = array_values(array_unique($seenRaw));
            if ($provider === ApiConnectionProviders::OPENROUTER) {
                $this->upsertOpenRouterFreeRouter($connection);
                $seenRaw[] = OpenRouterModelEconomics::FREE_ROUTER_ID;
                $seenRaw = array_values(array_unique($seenRaw));
            }
            if ($seenRaw === []) {
                return false;
            }
            $this->deactivateMissingModels($connectionId, $seenRaw);
            if ($provider === ApiConnectionProviders::OPENROUTER) {
                (new AiModelPrimaryTypeClassifier())->classifyConnection($connection);
            }

            return true;
        } catch (\Throwable $exception) {
            logger()->error('syncOpenAiCompatibleModels failed', [
                'connection_id' => $connectionId,
                'message' => $exception->getMessage(),
                'exception' => $exception::class,
            ]);

            return false;
        }
    }

    private function upsertOpenRouterFreeRouter(ApiConnection $connection): void
    {
        $raw = OpenRouterModelEconomics::FREE_ROUTER_ID;
        $existing = SeoAiModel::query()
            ->where('api_connection_id', $connection->id)
            ->where('raw_model_name', $raw)
            ->first();
        SeoAiModel::query()->updateOrCreate(
            [
                'api_connection_id' => (int) $connection->id,
                'raw_model_name' => $raw,
            ],
            $this->mergeSyncPayload((int) $connection->id, $raw, [
                'category' => AiModelCategory::GEMINI_FLASH,
                'display_name' => OpenRouterModelEconomics::FREE_ROUTER_LABEL,
                'priority' => $existing?->priority ?: 999,
                'status' => $existing?->status ?: SeoAiModel::STATUS_ACTIVE,
                'capabilities' => [
                    'source' => ApiConnectionProviders::OPENROUTER,
                    'provider_metadata' => [
                        'pricing' => ['prompt' => '0', 'completion' => '0'],
                        'architecture' => ['modality' => 'text->text'],
                    ],
                    'resolved' => [
                        \Omnichannel\Addons\AiPrompt\Support\AiModelCapability::TextGenerate->value,
                        \Omnichannel\Addons\AiPrompt\Support\AiModelCapability::TextReasoning->value,
                        \Omnichannel\Addons\AiPrompt\Support\AiModelCapability::StructuredOutput->value,
                    ],
                ],
                'last_error' => null,
            ]),
        );
    }

    public function syncDeepSeekModels(int $connectionId): bool
    {
        $connection = ApiConnection::query()->find($connectionId);
        if ($connection === null || $connection->provider !== ApiConnectionProviders::DEEPSEEK) {
            return false;
        }

        if (blank($connection->api_key)) {
            return false;
        }

        $seenRaw = $this->seedDeepSeekCatalogModels($connectionId);
        $client = $this->deepSeekClient ?? (function_exists('app') ? app(DeepSeekChatClient::class) : new DeepSeekChatClient());

        try {
            foreach ($client->listModels($connection) as $row) {
                $rawName = (string) ($row['id'] ?? '');
                if ($rawName === '') {
                    continue;
                }
                $classified = $this->classifyDeepSeekModel($rawName);
                if ($classified === null) {
                    continue;
                }
                $seenRaw[] = $rawName;
                SeoAiModel::query()->updateOrCreate(
                    [
                        'api_connection_id' => $connectionId,
                        'raw_model_name' => $rawName,
                    ],
                    $this->mergeSyncPayload($connectionId, $rawName, [
                        'category' => $classified['category'],
                        'display_name' => (string) ($row['display_name'] ?? $rawName),
                        'priority' => $classified['priority'],
                        'status' => SeoAiModel::STATUS_ACTIVE,
                        'capabilities' => [
                            'source' => 'deepseek',
                            'resolved' => $this->capabilityRegistry->capabilitiesFor($connection, $rawName),
                        ],
                        'last_error' => null,
                    ]),
                );
            }
        } catch (Throwable $exception) {
            logger()->error('syncDeepSeekModels failed', [
                'connection_id' => $connectionId,
                'message' => $exception->getMessage(),
            ]);
        }

        $seenRaw = array_values(array_unique($seenRaw));
        if ($seenRaw === []) {
            return false;
        }

        $this->deactivateMissingModels($connectionId, $seenRaw);

        return true;
    }

    /**
     * @return array{category: string, priority: int}|null
     */
    private function classifyDeepSeekModel(string $rawName): ?array
    {
        $lower = strtolower($rawName);
        if (! str_starts_with($lower, 'deepseek')) {
            return null;
        }
        if (str_contains($lower, 'image') || str_contains($lower, 'video')) {
            return null;
        }
        if (str_contains($lower, 'reason')) {
            return ['category' => AiModelCategory::DEEPSEEK_REASONER, 'priority' => 200];
        }

        return ['category' => AiModelCategory::DEEPSEEK_CHAT, 'priority' => 150];
    }

    /**
     * @return list<string>
     */
    private function seedDeepSeekCatalogModels(int $connectionId): array
    {
        $catalog = [
            ['deepseek-chat', 'DeepSeek Chat', AiModelCategory::DEEPSEEK_CHAT, 150],
            ['deepseek-reasoner', 'DeepSeek Reasoner', AiModelCategory::DEEPSEEK_REASONER, 200],
        ];
        $seeded = [];
        $connection = ApiConnection::query()->find($connectionId);
        foreach ($catalog as [$raw, $label, $category, $priority]) {
            SeoAiModel::query()->updateOrCreate(
                [
                    'api_connection_id' => $connectionId,
                    'raw_model_name' => $raw,
                ],
                $this->mergeSyncPayload($connectionId, $raw, [
                    'category' => $category,
                    'display_name' => $label,
                    'priority' => $priority,
                    'status' => SeoAiModel::STATUS_ACTIVE,
                    'capabilities' => [
                        'source' => 'catalog',
                        'resolved' => $connection instanceof ApiConnection
                            ? $this->capabilityRegistry->capabilitiesFor($connection, $raw)
                            : [],
                    ],
                    'last_error' => null,
                ]),
            );
            $seeded[] = $raw;
        }

        return $seeded;
    }

    private function seedClaudeFallbackModels(int $connectionId): bool
    {
        $fallbacks = [
            ['claude-sonnet-4-20250514', 'Claude Sonnet 4', AiModelCategory::CLAUDE_SONNET, 200],
            ['claude-opus-4-20250514', 'Claude Opus 4', AiModelCategory::CLAUDE_OPUS, 190],
            ['claude-3-5-sonnet-20240620', 'Claude 3.5 Sonnet', AiModelCategory::CLAUDE_SONNET, 150],
            ['claude-3-haiku-20240307', 'Claude 3 Haiku', AiModelCategory::CLAUDE_HAIKU, 100],
        ];

        foreach ($fallbacks as [$raw, $label, $category, $priority]) {
            SeoAiModel::query()->updateOrCreate(
                [
                    'api_connection_id' => $connectionId,
                    'raw_model_name' => $raw,
                ],
                [
                    'category' => $category,
                    'display_name' => $label,
                    'priority' => $priority,
                    'status' => SeoAiModel::STATUS_ACTIVE,
                    'capabilities' => $this->capabilitiesWithResolved($raw, ['source' => 'catalog']),
                ],
            );
        }

        return true;
    }
}
