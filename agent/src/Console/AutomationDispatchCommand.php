<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Console;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Registry\BusinessEventRegistry;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Services\BusinessEventDispatcher;
use Omnichannel\Addons\Content\Models\SeoArticle;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectRun;
use Omnichannel\Addons\ContentProjects\Models\SeoProjectTask;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Model;

final class AutomationDispatchCommand extends Command
{
    protected $signature = 'automation:dispatch
        {event : Business event name}
        {--subject-type= : article|task|run}
        {--subject-id= : Subject primary key}
        {--payload= : JSON payload}
        {--dry-run : Validate only, do not dispatch}
        {--uuid= : Optional event UUID for idempotency}';

    protected $description = 'Dispatch a business event through BusinessEventDispatcher.';

    public function handle(
        BusinessEventRegistry $eventRegistry,
        BusinessEventDispatcher $dispatcher,
    ): int {
        if (app()->environment('production')) {
            $this->warn('Running in PRODUCTION environment. Proceed with caution.');
        }

        $eventName = (string) $this->argument('event');

        if (! $eventRegistry->has($eventName)) {
            $this->error("Event [{$eventName}] is not registered.");

            return self::FAILURE;
        }

        $payload = $this->parseJsonOption('payload');
        if ($payload === false) {
            return self::FAILURE;
        }
        $payload ??= [];

        $subject = $this->resolveSubject(
            (string) ($this->option('subject-type') ?? ''),
            $this->option('subject-id'),
        );

        if ($subject === false) {
            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $definition = $eventRegistry->get($eventName);
            $this->info('[dry-run] Event is registered.');
            $this->line('  name: '.$definition->name);
            $this->line('  module: '.$definition->module);
            $this->line('  description: '.$definition->description);
            if ($subject instanceof Model) {
                $this->line('  subject: '.$subject::class.'#'.$subject->getKey());
            } elseif (is_string($subject)) {
                $this->line('  subject: '.$subject);
            }
            $this->line('  payload: '.json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

            return self::SUCCESS;
        }

        $eventUuid = $this->option('uuid');
        $eventUuid = is_string($eventUuid) && $eventUuid !== '' ? $eventUuid : null;

        $businessEvent = $dispatcher->dispatch(
            eventName: $eventName,
            subject: $subject,
            payload: $payload,
            context: [],
            eventUuid: $eventUuid,
        );

        $this->info("Dispatched event [{$eventName}] uuid={$businessEvent->event_uuid} id={$businessEvent->id}");

        return self::SUCCESS;
    }

    private function resolveSubject(string $type, mixed $id): Model|string|null|false
    {
        if ($type === '' && ($id === null || $id === '')) {
            return null;
        }

        $class = match ($type) {
            'article' => SeoArticle::class,
            'task' => SeoProjectTask::class,
            'run' => SeoProjectRun::class,
            default => null,
        };

        if ($class === null) {
            $this->error('--subject-type must be one of: article, task, run.');

            return false;
        }

        if ($id === null || $id === '') {
            return $class;
        }

        $record = $class::query()->find((int) $id);
        if (! $record instanceof Model) {
            $this->error("Subject [{$type}#{$id}] not found.");

            return false;
        }

        return $record;
    }

    /**
     * @return array<string, mixed>|null|false
     */
    private function parseJsonOption(string $name): array|null|false
    {
        $raw = $this->option($name);
        if ($raw === null || $raw === '') {
            return null;
        }

        if (! is_string($raw)) {
            $this->error("--{$name} must be a JSON string.");

            return false;
        }

        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->error("--{$name} invalid JSON: ".$e->getMessage());

            return false;
        }

        if (! is_array($decoded)) {
            $this->error("--{$name} must decode to a JSON object.");

            return false;
        }

        return $decoded;
    }
}
