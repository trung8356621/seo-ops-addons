<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Contracts;

/**
 * Cross-addon capability: search-intelligence reports semantic infra health;
 * SEO owns publication into OperationalNotificationService / Operational Alert Hook.
 */
interface SemanticServiceNotificationCapability
{
    public const ID = 'semantic-service.notifications';

    /**
     * @param  array<string, mixed>  $context  Safe diagnostics only (no secrets/stacks).
     */
    public function serviceUnavailable(array $context = []): void;

    /**
     * @param  array<string, mixed>  $context
     */
    public function serviceDegraded(array $context = []): void;

    /**
     * @param  array<string, mixed>  $context
     */
    public function serviceRecovered(array $context = []): void;
}
