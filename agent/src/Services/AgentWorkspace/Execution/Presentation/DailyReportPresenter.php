<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Services\AgentWorkspace\Execution\Presentation;

final class DailyReportPresenter
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function present(array $data): array
    {
        $safe = ReadResultPresenter::withoutInternalKeys($data);
        $lines = ['Báo cáo hôm nay'];

        foreach ($safe as $key => $value) {
            if (! is_scalar($value)) {
                continue;
            }
            $lines[] = ucfirst(str_replace('_', ' ', (string) $key)).': '.(string) $value;
        }

        if (count($lines) === 1) {
            $lines[] = 'Chưa có dữ liệu báo cáo.';
        }

        return ReadResultPresenter::card('Báo cáo hôm nay', $lines);
    }
}
