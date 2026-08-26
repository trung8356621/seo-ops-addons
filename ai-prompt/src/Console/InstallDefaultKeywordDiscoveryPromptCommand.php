<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Console;

use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultKeywordDiscoveryPromptInstaller;
use Illuminate\Console\Command;

/**
 * Idempotent: tạo Prompt + Settings binding keyword.discovery.structured nếu thiếu.
 * Dùng: php artisan seo:prompt:install-default-keyword-discovery
 * Reset markdown về canonical JSON: --restore
 */
final class InstallDefaultKeywordDiscoveryPromptCommand extends Command
{
    protected $signature = 'seo:prompt:install-default-keyword-discovery {--restore : Restore markdown from canonical Hook JSON (overwrites this system default prompt)}';

    protected $description = 'Idempotent install default Prompt + Settings binding for Keyword Discovery';

    public function handle(DefaultKeywordDiscoveryPromptInstaller $installer): int
    {
        $result = $installer->install(restoreCanonical: (bool) $this->option('restore'));

        $this->info(sprintf(
            'prompt_id=%d created=%s binding_set=%s restored=%s',
            $result['prompt_id'],
            $result['created'] ? 'yes' : 'no',
            $result['binding_set'] ? 'yes' : 'no',
            $result['restored'] ? 'yes' : 'no',
        ));

        if (! $result['binding_set'] && ! $result['created'] && ! $result['restored']) {
            $this->comment('Binding đã có — không ghi đè Prompt Settings / markdown của operator.');
        }

        return self::SUCCESS;
    }
}
