<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SearchIntelligence\Services\Semantic;

use App\Core\Capability\CapabilityRegistry;
use App\Support\RuntimeLogger;
use Omnichannel\Addons\SearchIntelligence\Contracts\SemanticServiceNotificationCapability;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticTimeoutException;
use Omnichannel\Addons\SearchIntelligence\Services\Semantic\Exceptions\SemanticUnavailableException;
use Throwable;

/**
 * Reports semantic infra health transitions to the SEO notification capability.
 * No-ops when semantic integration is disabled or capability is unbound.
 */
final class SemanticServiceHealthReporter
{
    public function __construct(private readonly ?CapabilityRegistry $capabilities = null) {}

    public function report(SemanticServiceHealthSnapshot $snapshot): void
    {
        if (! $this->enabled()) {
            return;
        }

        $notifier = $this->notifier();
        if ($notifier === null) {
            return;
        }

        try {
            match ($snapshot->status) {
                SemanticServiceHealthStatus::Healthy => $notifier->serviceRecovered($snapshot->context()),
                SemanticServiceHealthStatus::Degraded => $notifier->serviceDegraded($snapshot->context() + [
                    'reason' => $snapshot->reason,
                ]),
                SemanticServiceHealthStatus::Down => $notifier->serviceUnavailable($snapshot->context() + [
                    'reason' => $snapshot->reason,
                ]),
            };
        } catch (Throwable $exception) {
            RuntimeLogger::report($exception, [
                'source' => self::class,
                'health_status' => $snapshot->status->value,
            ]);
        }
    }

    /**
     * Runtime transport failure from SemanticAnalyticsClient (unavailable / timeout / 502–504).
     */
    public function reportTransportFailure(Throwable $exception): void
    {
        if (! $this->enabled()) {
            return;
        }

        if (! $exception instanceof SemanticUnavailableException
            && ! $exception instanceof SemanticTimeoutException) {
            return;
        }

        $host = $this->safeConfiguredHost();
        $errorCode = $exception instanceof SemanticTimeoutException
            ? 'semantic_timeout'
            : $exception->errorCode;

        $this->report(new SemanticServiceHealthSnapshot(
            status: SemanticServiceHealthStatus::Down,
            errorCode: $errorCode,
            reason: 'Semantic service transport failure during an API call.',
            baseHost: $host,
        ));
    }

    /**
     * Successful semantic JSON response proves the service is reachable/serving.
     * Application 4xx never reaches here (thrown as transport before success path).
     */
    public function reportReachable(): void
    {
        if (! $this->enabled()) {
            return;
        }

        $this->report(new SemanticServiceHealthSnapshot(
            status: SemanticServiceHealthStatus::Healthy,
            errorCode: '',
            reason: 'Semantic service responded successfully.',
            baseHost: $this->safeConfiguredHost(),
        ));
    }

    public function enabled(): bool
    {
        return (bool) config('semantic.enabled', false);
    }

    private function notifier(): ?SemanticServiceNotificationCapability
    {
        $registry = $this->capabilities;
        if ($registry === null && function_exists('app')) {
            try {
                $registry = app(CapabilityRegistry::class);
            } catch (Throwable) {
                return null;
            }
        }
        if ($registry === null) {
            return null;
        }

        return $registry->getAs(
            SemanticServiceNotificationCapability::ID,
            SemanticServiceNotificationCapability::class,
        );
    }

    private function safeConfiguredHost(): ?string
    {
        $base = rtrim((string) config('semantic.url', ''), '/');
        if ($base === '') {
            return null;
        }
        $parts = parse_url($base);
        if (! is_array($parts) || empty($parts['host'])) {
            return null;
        }
        $port = isset($parts['port']) ? ':'.$parts['port'] : '';

        return (string) $parts['host'].$port;
    }
}
