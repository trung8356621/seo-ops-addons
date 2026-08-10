<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Console;

use Omnichannel\Addons\ContentProjects\Jobs\RunContentProjectArticleJob;
use Omnichannel\Addons\ContentProjects\Support\Queue\ContentProjectQueueRuntimeSafety;
use Omnichannel\Addons\ContentProjects\Support\RunEngine\ContentProjectRunEngineFeature;
use Illuminate\Console\Command;
use ReflectionClass;
use Throwable;

/**
 * Read-only preflight: database retry_after vs RunContentProjectArticleJob contract.
 * Does not query/consume jobs table; does not mutate cache/DB/queue.
 */
final class QueueRuntimeCheckCommand extends Command
{
    protected $signature = 'seo:queue-runtime-check';

    protected $description = 'Read-only check: Content Project queue contract and database retry_after > job timeout.';

    public function handle(): int
    {
        try {
            $snapshot = $this->collectSnapshot();
        } catch (Throwable $e) {
            $this->error('FAIL could not read queue runtime contract: '.$e->getMessage());
            $this->line('aaPanel queue coverage cannot be verified from Laravel source.');
            $this->line('Expected dedicated worker queue: '.ContentProjectQueueRuntimeSafety::DEFAULT_RUN_QUEUE);

            return self::FAILURE;
        }

        $result = ContentProjectQueueRuntimeSafety::evaluate($snapshot);

        foreach ($result['lines'] as $line) {
            $this->line($line);
        }

        return $result['safe'] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * @return array{
     *     queue_connection: string,
     *     retry_after: mixed,
     *     run_queue: string,
     *     job_timeout: int,
     *     job_tries: int,
     *     job_unique_for: int,
     *     pcntl: bool
     * }
     */
    private function collectSnapshot(): array
    {
        $connection = (string) config('queue.default', '');
        $retryAfter = null;
        if ($connection !== '') {
            $retryAfter = config('queue.connections.'.$connection.'.retry_after');
        }

        $job = new ReflectionClass(RunContentProjectArticleJob::class);

        return [
            'queue_connection' => $connection,
            'retry_after' => $retryAfter,
            'run_queue' => ContentProjectRunEngineFeature::queueName(),
            'job_timeout' => (int) $job->getProperty('timeout')->getDefaultValue(),
            'job_tries' => (int) $job->getProperty('tries')->getDefaultValue(),
            'job_unique_for' => (int) $job->getProperty('uniqueFor')->getDefaultValue(),
            'pcntl' => extension_loaded('pcntl'),
        ];
    }
}
