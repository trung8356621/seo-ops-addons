<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Console;

use Omnichannel\Addons\AiPrompt\Services\PromptOwnership\DefaultTopicalMapAuditPromptInstaller;
use Illuminate\Console\Command;

/**
 * Idempotent: Prompt + Settings binding seo_keywords.topical_map_audit.
 * php artisan seo:prompt:install-default-topical-map-audit
 */
final class InstallDefaultTopicalMapAuditPromptCommand extends Command
{
    protected $signature = 'seo:prompt:install-default-topical-map-audit {--restore : Restore markdown from canonical Hook JSON (overwrites this system default prompt)}';

    protected $description = 'Idempotent install default Prompt + Settings binding for Topical Map Audit';

    public function handle(DefaultTopicalMapAuditPromptInstaller $installer): int
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
            $this->comment('Binding already present — not overwriting operator markdown/bindings.');
        }

        return self::SUCCESS;
    }
}
