<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Console;

use Omnichannel\Addons\Agent\Services\AgentWorkspace\Observability\Evaluation\AgentEvaluationRunner;
use Omnichannel\Addons\Agent\Services\AgentWorkspace\V1\AgentV1ReadinessService;
use Omnichannel\Addons\SearchFoundation\Services\SeoDatabaseConnectionService;
use Illuminate\Console\Command;

final class AgentV1DoctorCommand extends Command
{
    protected $signature = 'agent:v1:doctor
        {--connection= : Optional connection hash (informational)}
        {--json : Print JSON}
        {--fix-safe : Install datasets / refresh Agent caches only}
        {--skip-provider : Skip provider probe (default true)}
        {--sync : Persist readiness JSON under storage/app/agent-audits}';

    protected $description = 'Non-destructive Agent Workspace v1 readiness doctor.';

    public function handle(
        SeoDatabaseConnectionService $databaseConnection,
        AgentV1ReadinessService $doctor,
        AgentEvaluationRunner $evaluationRunner,
    ): int {
        $databaseConnection->bootstrapLegacySharedConnection();
        unset($evaluationRunner); // available for future dry-run hook; doctor itself stays non-executing

        $result = $doctor->run(
            fixSafe: (bool) $this->option('fix-safe'),
            skipProvider: $this->option('skip-provider') !== '0',
        );

        $this->info('overall='.$result['overall']);
        foreach ($result['checks'] as $check) {
            $this->line(sprintf('[%s] %s — %s', $check['status'], $check['id'], $check['message']));
        }

        if ($this->option('sync')) {
            $path = storage_path('app/agent-audits/v1-doctor.json');
            $dir = dirname($path);
            if (! is_dir($dir)) {
                @mkdir($dir, 0775, true);
            }
            file_put_contents($path, json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE) ?: '{}');
            $this->line('wrote='.$path);
        }

        if ($this->option('json')) {
            $this->line(json_encode($result, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: '{}');
        }

        return ($result['overall'] ?? '') === 'not_ready' ? self::FAILURE : self::SUCCESS;
    }
}
