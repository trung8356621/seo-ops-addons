<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use App\Models\ApiConnection;
use Omnichannel\Addons\AiPrompt\Support\AiModelLabelPresenter;

/**
 * Canonical one-line execution-target labels shared by Models + Routing.
 */
final class AiExecutionTargetPresenter
{
    public function __construct(
        private readonly AiConnectionPresenter $connections = new AiConnectionPresenter(),
        private readonly AiModelLabelPresenter $labels = new AiModelLabelPresenter(),
    ) {}

    public function forgetMemo(): void
    {
        $this->connections->forgetMemo();
    }

    /**
     * @return array{short_code: string, badge_variant: string, model_name: string, full_label: string}
     */
    public function present(
        ApiConnection $connection,
        string $providerModelId,
        ?string $fallbackDisplay = null,
        ?int $userId = null,
    ): array {
        $modelName = $this->labels->normal($providerModelId, $fallbackDisplay);
        $shortCode = $this->connections->shortCode($connection, $userId);

        return [
            'short_code' => $shortCode,
            'badge_variant' => $this->connections->badgeVariant($connection),
            'model_name' => $modelName,
            'full_label' => '['.$shortCode.'] '.$modelName,
        ];
    }

    /**
     * @return array{short_code: string, badge_variant: string, model_name: string, full_label: string}
     */
    public function presentNamed(
        ApiConnection $connection,
        string $modelDisplayName,
        ?int $userId = null,
    ): array {
        $modelName = trim($modelDisplayName);
        $shortCode = $this->connections->shortCode($connection, $userId);

        return [
            'short_code' => $shortCode,
            'badge_variant' => $this->connections->badgeVariant($connection),
            'model_name' => $modelName,
            'full_label' => '['.$shortCode.'] '.$modelName,
        ];
    }
}
