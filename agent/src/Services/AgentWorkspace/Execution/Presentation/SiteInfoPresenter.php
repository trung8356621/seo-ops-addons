<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Presentation;

final class SiteInfoPresenter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function present(array $data): array
    {
        if (isset($data['error']) && is_scalar($data['error'])) {
            return ReadResultPresenter::card('Site health', [
                'Lỗi: '.(string) $data['error'],
            ]);
        }

        if (isset($data['sites']) && is_array($data['sites'])) {
            if ($data['sites'] === []) {
                return ReadResultPresenter::card('Site health', [
                    'Thiếu dữ liệu site health.',
                    'Kiểm tra site_ref context hoặc chạy /site-health --refresh.',
                ]);
            }
            $first = $data['sites'][0] ?? null;
            if (is_array($first)) {
                $data = $first;
            }
        }

        $domain = (string) ($data['domain'] ?? $data['name'] ?? 'site');
        $lines = ['Site health — '.$domain, ''];

        $lines[] = 'Connection';
        $lines[] = 'WP reachable: '.$this->label((string) ($data['wp_reachable'] ?? 'unknown'));
        if (! empty($data['wp_reachable_reason'])) {
            $lines[] = '  · '.(string) $data['wp_reachable_reason'];
        }
        $lines[] = 'Token: '.$this->tokenLabel((string) ($data['token_ok'] ?? 'unknown'));
        if (! empty($data['token_ok_reason'])) {
            $lines[] = '  · '.(string) $data['token_ok_reason'];
        }
        if (! empty($data['checked_at'])) {
            $lines[] = 'Last checked: '.(string) $data['checked_at'];
        }
        if (! empty($data['plugin_version'])) {
            $lines[] = 'Plugin: '.(string) $data['plugin_version'];
        }

        $lines[] = '';
        $lines[] = 'Synchronization';
        $lines[] = 'Last sync: '.(string) ($data['sync_status'] ?? $data['last_sync'] ?? '—');
        if (! empty($data['sync_started_at'])) {
            $lines[] = 'Started: '.(string) $data['sync_started_at'];
        }
        if (! empty($data['sync_finished_at']) || ! empty($data['last_sync'])) {
            $lines[] = 'Finished: '.(string) ($data['sync_finished_at'] ?? $data['last_sync']);
        }
        $lines[] = 'Snapshot: '.(! empty($data['snapshot_received']) ? 'received' : 'not received');
        $lines[] = 'Capabilities: '.(! empty($data['capabilities_loaded']) ? 'loaded' : 'missing');

        $lines[] = '';
        $lines[] = 'Publishing';
        $lines[] = 'Waiting: '.(string) (int) ($data['waiting_articles'] ?? 0);
        $lines[] = 'Processing: '.(string) (int) ($data['publishing'] ?? 0);
        $lines[] = 'Failed: '.(string) (int) ($data['publish_failed'] ?? 0);

        return ReadResultPresenter::card('Site health', $lines);
    }

    private function label(string $value): string
    {
        return match ($value) {
            'yes' => 'yes',
            'no' => 'no',
            'stale' => 'stale',
            'never_checked' => 'never checked',
            'unknown_due_to_missing_data' => 'unknown (missing data)',
            default => $value !== '' ? $value : 'unknown',
        };
    }

    private function tokenLabel(string $value): string
    {
        return match ($value) {
            'yes' => 'valid',
            'configured' => 'configured (unverified)',
            'no' => 'invalid',
            'stale' => 'stale',
            'never_checked' => 'never checked',
            'unknown_due_to_missing_data' => 'unknown (missing data)',
            default => $value !== '' ? $value : 'unknown',
        };
    }
}
