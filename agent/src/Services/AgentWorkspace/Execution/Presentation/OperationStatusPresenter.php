<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Presentation;

final class OperationStatusPresenter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function present(array $data): array
    {
        $operation = is_array($data['operation'] ?? null) ? $data['operation'] : $data;
        $safe = ReadResultPresenter::withoutInternalKeys(is_array($operation) ? $operation : []);
        $lines = ['Trạng thái operation'];

        foreach (['status', 'message', 'capability', 'created_at', 'finished_at'] as $key) {
            // capability key here is business action name when present — skip opaque refs only.
            if ($key === 'capability' && isset($safe[$key]) && is_string($safe[$key]) && str_contains($safe[$key], '.')) {
                $lines[] = 'Hành động: '.$safe[$key];
                continue;
            }
            if (! isset($safe[$key]) || ! is_scalar($safe[$key])) {
                continue;
            }
            if ($key === 'capability') {
                continue;
            }
            $lines[] = ucfirst(str_replace('_', ' ', $key)).': '.(string) $safe[$key];
        }

        if (count($lines) === 1) {
            $lines[] = 'Không tìm thấy operation.';
        }

        return ReadResultPresenter::card('Trạng thái operation', $lines);
    }
}
