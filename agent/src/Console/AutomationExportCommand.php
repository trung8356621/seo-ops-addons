<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Console;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\AutomationImportExportService;
use Omnichannel\Addons\Agent\Automation\Exceptions\AutomationException;
use Illuminate\Console\Command;

final class AutomationExportCommand extends Command
{
    protected $signature = 'automation:export {code : Automation rule code}';

    protected $description = 'Export automation rule as JSON schema v3 (secrets redacted).';

    public function handle(AutomationImportExportService $export): int
    {
        $code = (string) $this->argument('code');

        try {
            $payload = $export->export($code);
        } catch (AutomationException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }
}
