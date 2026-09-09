<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Console;

use Illuminate\Console\Command;
use Omnichannel\Addons\AiPrompt\Services\AiHistoryResetService;

/**
 * DESTRUCTIVE: wipe AI History / execution runtime rows. Keeps Prompt definitions + versions.
 *
 * php artisan seo:ai:reset-history
 */
final class ResetAiHistoryCommand extends Command
{
    protected $signature = 'seo:ai:reset-history
        {--force : Skip confirmation (required in non-interactive)}';

    protected $description = 'DESTRUCTIVE: delete AI History/execution/runtime records. Preserves Prompt definitions and Prompt Versions.';

    public function handle(AiHistoryResetService $reset): int
    {
        $this->error('DESTRUCTIVE OPERATION');
        $this->warn('This permanently deletes AI History / PromptResult / routing-attempt / test-result rows.');
        $this->warn('Prompt definitions and Prompt Versions are preserved.');
        $this->warn('Content Project articles/items are NOT deleted.');

        if (! $this->option('force') && ! $this->confirm('Delete all AI History/execution data?', false)) {
            $this->comment('Aborted.');

            return self::SUCCESS;
        }

        $counts = $reset->reset();

        $this->info('AI History reset complete.');
        foreach ($counts as $key => $value) {
            $this->line(sprintf('  %s: %d', $key, $value));
        }

        return self::SUCCESS;
    }
}
