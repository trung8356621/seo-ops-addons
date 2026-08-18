<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Services;

use Omnichannel\Addons\AiPrompt\DataTransfer\AiRoutingContext;
use Omnichannel\Addons\AiPrompt\Support\AiExecutionProfile;
use Omnichannel\Addons\AiPrompt\Support\AiModelArea;

/**
 * Request-scoped projection of enabled execution targets for Models + Routing.
 * Does not include OpenRouter discovered-but-disabled inventory.
 */
final class AiModelInventory
{
    /** @var array<string, list<array<string, mixed>>> */
    private array $areaRowsMemo = [];

    /** @var array<string, array{enabled: int, available: int}>|null */
    private ?array $countsMemo = null;

    /** @var array<string, array<string, array{short_code: string, badge_variant: string, model_name: string, full_label: string}>> */
    private array $optionsMemo = [];

    /** @var array<string, int> */
    private array $eligibleCountMemo = [];

    public function __construct(
        private readonly AiCenterModelPresenter $presenter = new AiCenterModelPresenter(),
        private readonly AiRoutingTargetService $targets = new AiRoutingTargetService(new ModelCapabilityRegistry()),
        private readonly AiModelFamilyCatalog $families = new AiModelFamilyCatalog(),
        private readonly AiModelPriorityService $priorities = new AiModelPriorityService(),
    ) {}

    /**
     * @param  array{search?: string, provider?: string, status?: string, technical?: bool}  $filters
     * @return list<array<string, mixed>>
     */
    public function enabledRows(int $userId, AiModelArea $area, array $filters = []): array
    {
        $key = $userId.'|'.$area->value.'|'.md5((string) json_encode($filters));
        if (! isset($this->areaRowsMemo[$key])) {
            $this->areaRowsMemo[$key] = $this->presenter->areaRows($userId, $area, $filters);
        }

        return $this->areaRowsMemo[$key];
    }

    /**
     * @return array<string, array{enabled: int, available: int}>
     */
    public function areaCounts(int $userId): array
    {
        return $this->countsMemo ??= $this->presenter->areaCounts($userId);
    }

    /**
     * Enabled targets for a profile as connectionId|familyKey => presentation.
     *
     * @return array<string, array{short_code: string, badge_variant: string, model_name: string, full_label: string}>
     */
    public function executionOptions(int $userId, AiExecutionProfile $profile): array
    {
        $memoKey = $userId.'|'.$profile->value;
        if (isset($this->optionsMemo[$memoKey])) {
            return $this->optionsMemo[$memoKey];
        }

        $labels = new AiExecutionTargetPresenter();
        $options = [];
        foreach ($this->targets->liveCompatibleCandidates($userId, $profile) as $candidate) {
            $family = $this->families->aggregatorFamily($candidate->model);
            if ($family === null) {
                continue;
            }
            $execKey = ((int) $candidate->connection->id).'|'.$family->familyKey;
            if (isset($options[$execKey])) {
                continue;
            }
            $options[$execKey] = $labels->presentNamed(
                $candidate->connection,
                $family->displayName,
                $userId,
            );
        }

        return $this->optionsMemo[$memoKey] = $options;
    }

    public function eligibleCount(int $userId, AiExecutionProfile $profile): int
    {
        $memoKey = $userId.'|'.$profile->value;
        if (isset($this->eligibleCountMemo[$memoKey])) {
            return $this->eligibleCountMemo[$memoKey];
        }

        return $this->eligibleCountMemo[$memoKey] = count(
            $this->targets->liveCompatibleCandidates($userId, $profile),
        );
    }

    /**
     * @param  list<string>  $allowedKeys
     * @return list<string>
     */
    public function orderedExecutionKeys(int $userId, AiExecutionProfile $profile, array $allowedKeys = []): array
    {
        $keys = [];
        foreach ($this->targets->eligibleCandidates(
            $userId,
            $profile,
            new AiRoutingContext(userId: $userId, allowedFamilyKeys: null),
        ) as $candidate) {
            $family = $this->families->aggregatorFamily($candidate->model);
            if ($family === null) {
                continue;
            }
            $execKey = ((int) $candidate->connection->id).'|'.$family->familyKey;
            if ($allowedKeys !== []
                && ! in_array($execKey, $allowedKeys, true)
                && ! in_array($family->familyKey, $allowedKeys, true)) {
                continue;
            }
            if (! in_array($execKey, $keys, true)) {
                $keys[] = $execKey;
            }
        }

        return $keys;
    }

    public function forget(): void
    {
        $this->areaRowsMemo = [];
        $this->countsMemo = null;
        $this->optionsMemo = [];
        $this->eligibleCountMemo = [];
        $this->presenter->forgetMemo();
        $this->targets->forgetMemo();
        $this->priorities->forgetMemo();
    }
}
