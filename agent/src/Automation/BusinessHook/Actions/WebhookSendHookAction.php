<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Actions;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Contracts\AutomationActionHandler;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionContext;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionResult;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

final class WebhookSendHookAction implements AutomationActionHandler
{
    public function handle(AutomationActionContext $context, array $input, array $settings): AutomationActionResult
    {
        $url = trim((string) ($settings['url'] ?? $settings['webhook_url'] ?? ''));
        if ($url === '') {
            return AutomationActionResult::failure('WEBHOOK_URL_MISSING', 'Webhook URL is required in settings.');
        }

        if (! $this->isAllowedUrl($url)) {
            return AutomationActionResult::failure('WEBHOOK_URL_BLOCKED', 'Webhook URL is not allowed.');
        }

        $payload = array_merge($input, [
            'event_name' => $context->businessEvent->event_name,
            'event_uuid' => $context->businessEvent->event_uuid,
            'correlation_id' => $context->correlationId,
        ]);

        try {
            $response = Http::timeout(15)
                ->acceptJson()
                ->asJson()
                ->post($url, $payload);

            if (! $response->successful()) {
                return AutomationActionResult::failure(
                    'WEBHOOK_HTTP_ERROR',
                    'Webhook responded with HTTP '.$response->status(),
                    ['status' => $response->status()],
                );
            }

            return AutomationActionResult::success(
                output: ['status' => $response->status()],
                message: 'Webhook sent.',
            );
        } catch (\Throwable $e) {
            Log::warning('automation.webhook.failed', ['url_host' => parse_url($url, PHP_URL_HOST), 'error' => $e->getMessage()]);

            return AutomationActionResult::failure('WEBHOOK_EXCEPTION', $e->getMessage());
        }
    }

    private function isAllowedUrl(string $url): bool
    {
        $parts = parse_url($url);
        if (! is_array($parts) || ! isset($parts['scheme'], $parts['host'])) {
            return false;
        }

        $scheme = strtolower((string) $parts['scheme']);
        if (! in_array($scheme, ['https', 'http'], true)) {
            return false;
        }

        $host = strtolower((string) $parts['host']);
        if (in_array($host, ['localhost', '127.0.0.1', '::1', '0.0.0.0'], true)) {
            return false;
        }

        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return ! $this->isPrivateIp($host);
        }

        return true;
    }

    private function isPrivateIp(string $ip): bool
    {
        return filter_var(
            $ip,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }
}
