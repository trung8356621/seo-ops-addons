<?php

declare(strict_types=1);

namespace Omnichannel\Addons\ContentProjects\Console;

use Omnichannel\Addons\ContentProjects\Services\ContentProject\Agent\ContentProjectAgentSessionService;
use Illuminate\Console\Command;

final class CleanupContentProjectAgentSessionsCommand extends Command
{
    protected $signature = 'seo:content-project:cleanup-agent-sessions';

    protected $description = 'Expire agent sessions past expires_at';

    public function handle(ContentProjectAgentSessionService $sessions): int
    {
        $count = $sessions->expirePastSessions();
        $this->info('Expired '.$count.' agent session(s).');

        return self::SUCCESS;
    }
}
