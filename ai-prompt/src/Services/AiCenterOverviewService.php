<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\Models\AiProviderTemplate;
use Omnichannel\Addons\AiPrompt\Models\SeoAiModel;
use Omnichannel\Addons\AiPrompt\Support\ApiConnectionProviders;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\Seo\Support\SeoAccessControl;

final class AiCenterOverviewService
{
    /**
     * @return array<string, mixed>
     */
    public function snapshot(?int $userId = null): array
    {
        $userId ??= (int) auth()->id();
        $providers = [];
        foreach ([
            ApiConnectionProviders::GEMINI,
            ApiConnectionProviders::DEEPSEEK,
            ApiConnectionProviders::OPENROUTER,
            ApiConnectionProviders::CLAUDE,
        ] as $key) {
            $conn = ApiConnection::query()
                ->where('provider', $key)
                ->where(function ($query) use ($userId): void {
                    $query->where('user_id', $userId)->orWhere('is_global', true);
                })
                ->where('status', 'active')
                ->whereNotNull('api_key')
                ->first();
            $providers[] = [
                'key' => $key,
                'label' => ApiConnectionProviders::label($key),
                'status' => $conn instanceof ApiConnection ? 'connected' : 'not_configured',
            ];
        }

        $models = SeoAiModel::query()
            ->whereHas('apiConnection', function ($query) use ($userId): void {
                $query->where(function ($inner) use ($userId): void {
                    $inner->where('user_id', $userId)->orWhere('is_global', true);
                });
            });
        $discovered = (clone $models)->count();
        $enabled = (clone $models)->where('status', SeoAiModel::STATUS_ACTIVE)
            ->when(
                \Illuminate\Support\Facades\Schema::hasColumn('seo_ai_models', 'is_hidden'),
                static fn ($q) => $q->where('is_hidden', false),
            )
            ->count();

        $routing = [];
        foreach (['text', 'image', 'video'] as $group) {
            $ready = false;
            foreach (AiExecutionProfile::inGroup($group) as $profile) {
                if (app(AiRoutingTargetService::class)->liveCompatibleCandidates($userId, $profile) !== []) {
                    $ready = true;
                    break;
                }
            }
            $routing[$group] = $ready ? 'ready' : 'empty';
        }

        return [
            'providers' => $providers,
            'routing' => $routing,
            'models_enabled' => $enabled,
            'models_discovered' => $discovered,
            'can_manage' => SeoAccessControl::canAccessManagerFeatures(),
            'templates' => AiProviderTemplate::query()->where('user_id', $userId)->count(),
        ];
    }
}
