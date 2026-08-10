<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Planning\Data;

final readonly class AgentPlanningValidationResult
{
    /**
     * @param  list<string>  $errors
     * @param  list<string>  $repairActions
     */
    public function __construct(
        public bool $ok,
        public ?AgentPlanningResponse $response = null,
        public array $errors = [],
        public array $repairActions = [],
        public float $adjustedConfidence = 0.0,
    ) {}
}
