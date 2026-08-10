<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Console;

use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookMigrationFlags;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookPromotionGate;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookRuntimeRegistry;
use Omnichannel\Addons\AiPrompt\PromptHooks\Runtime\PromptHookShadowParityRecorder;
use Illuminate\Console\Command;

final class PromptHookParityReportCommand extends Command
{
    protected $signature = 'seo:prompt-hooks:parity-report
                            {hook? : Hook key (optional — all if omitted)}
                            {--version= : Hook version filter}
                            {--evaluate : Run promotion gate evaluate}';

    protected $description = 'Dump in-process Prompt Hook parity aggregates (hosting: also grep prompt_hook.shadow_parity logs)';

    public function handle(
        PromptHookShadowParityRecorder $recorder,
        PromptHookPromotionGate $gate,
        PromptHookMigrationFlags $flags,
    ): int {
        $hook = $this->argument('hook');
        $version = (string) ($this->option('version') ?: '');

        $rows = $recorder->allReports();
        if (is_string($hook) && $hook !== '') {
            $rows = array_values(array_filter(
                $rows,
                static fn (array $r): bool => ($r['hook_key'] ?? '') === $hook
                    && ($version === '' || ($r['hook_version'] ?? '') === $version),
            ));
            if ($rows === [] && $version !== '') {
                $rows = [$recorder->reportFor($hook, $version)];
            } elseif ($rows === []) {
                $rows = [$recorder->reportFor($hook, $version)];
            }
        }

        if ($rows === []) {
            $this->warn('No in-process parity samples. On hosting, inspect logs: prompt_hook.shadow_parity');
            $this->line('Live shadow multi-worker remains blocked without durable budget store.');

            return self::SUCCESS;
        }

        foreach ($rows as $row) {
            $this->newLine();
            $this->info(($row['hook_key'] ?? '?').'@'.($row['hook_version'] ?? '?'));
            $this->table(
                ['field', 'value'],
                collect($row)->map(static fn (mixed $v, string $k): array => [$k, is_scalar($v) || $v === null ? (string) ($v ?? '') : json_encode($v)])->values()->all(),
            );

            if ($this->option('evaluate') && is_string($hook) && $hook !== '') {
                $result = $gate->evaluate($hook, $version !== '' ? $version : (string) ($row['hook_version'] ?? '0.1.0'), [
                    'from_mode' => 'shadow',
                    'to_mode' => 'hook',
                    'rollback_verified' => true,
                ]);
                $this->line('mode='.$flags->mode($hook)->value);
                $this->line('gate.allowed='.($result['allowed'] ? 'yes' : 'no'));
                $this->line('gate.samples='.$result['samples'].'/'.$result['threshold']);
                $this->line('gate.blockers='.implode(',', $result['blockers']));
            }
        }

        return self::SUCCESS;
    }
}
