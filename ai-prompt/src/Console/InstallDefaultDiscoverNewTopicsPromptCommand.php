<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Console;

use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultDiscoverNewTopicsPromptInstaller;
use Illuminate\Console\Command;

/**
 * Idempotent: Prompt + Settings binding seo_audit.discover_new_topics.
 * php artisan seo:prompt:install-default-discover-new-topics
 */
final class InstallDefaultDiscoverNewTopicsPromptCommand extends Command
{
    protected $signature = 'seo:prompt:install-default-discover-new-topics {--restore : Restore markdown from canonical Hook JSON (overwrites this system default prompt)}';

    protected $description = 'Idempotent install default Prompt + Settings binding for Discover New Topics';

    public function handle(DefaultDiscoverNewTopicsPromptInstaller $installer): int
    {
        $result = $installer->install(restoreCanonical: (bool) $this->option('restore'));

        $this->info(sprintf(
            'prompt_id=%d created=%s binding_set=%s restored=%s ownership_repaired=%s user_id=%d',
            $result['prompt_id'],
            $result['created'] ? 'yes' : 'no',
            $result['binding_set'] ? 'yes' : 'no',
            $result['restored'] ? 'yes' : 'no',
            ($result['ownership_repaired'] ?? false) ? 'yes' : 'no',
            (int) ($result['user_id'] ?? 0),
        ));

        if (! $result['binding_set'] && ! $result['created'] && ! $result['restored'] && ! ($result['ownership_repaired'] ?? false)) {
            $this->comment('Binding already present — not overwriting operator markdown/bindings.');
        }

        return self::SUCCESS;
    }
}
