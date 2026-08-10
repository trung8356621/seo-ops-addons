<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Extension\Contracts;

interface CapabilityContributor
{
    /**
     * Required keys mirror the core capability shape. Optional keys are
     * enriched/validated by {@see \Omnichannel\Addons\ContentProjects\Services\ContentProject\Application\Capabilities\CanonicalCapabilityRegistry}
     * when merging with core capabilities — `extension_id` is always set by
     * the canonical registry (any value here is overwritten).
     *
     * @return list<array{
     *     name: string,
     *     description: string,
     *     input_schema: array<string, mixed>,
     *     risk_level: string,
     *     output_schema?: array<string, mixed>,
     *     required_scopes?: list<string>,
     *     supports_dry_run?: bool,
     *     requires_confirmation?: bool,
     *     extension_id?: string,
     *     extension_version?: string,
     * }>
     */
    public function capabilities(): array;
}
