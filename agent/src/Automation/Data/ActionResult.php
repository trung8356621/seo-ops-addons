<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Data;

use Omnichannel\Addons\Agent\Automation\Enums\ActionRunStatus;

final class ActionResult
{
    /**
     * @param  array<string, mixed>  $output
     * @param  list<EventEnvelope|array<string, mixed>>  $events
     * @param  list<string>  $warnings
     * @param  array<string, mixed>|null  $error
     * @param  list<string>  $changed
     */
    public function __construct(
        public readonly bool $success,
        public readonly ActionRunStatus $status,
        public readonly array $output = [],
        public readonly array $events = [],
        public readonly array $warnings = [],
        public readonly ?array $error = null,
        public readonly array $changed = [],
    ) {}

    /**
     * @param  array<string, mixed>  $output
     * @param  list<EventEnvelope|array<string, mixed>>  $events
     * @param  list<string>  $warnings
     * @param  list<string>  $changed
     */
    public static function success(
        array $output = [],
        array $events = [],
        array $warnings = [],
        array $changed = [],
        ActionRunStatus $status = ActionRunStatus::Succeeded,
    ): self {
        return new self(
            success: true,
            status: $status,
            output: $output,
            events: $events,
            warnings: $warnings,
            error: null,
            changed: $changed,
        );
    }

    /**
     * @param  array<string, mixed>  $error
     * @param  list<string>  $warnings
     */
    public static function failure(
        string $code,
        string $message,
        array $error = [],
        array $warnings = [],
        ActionRunStatus $status = ActionRunStatus::Failed,
    ): self {
        return new self(
            success: false,
            status: $status,
            output: [],
            events: [],
            warnings: $warnings,
            error: array_merge(['code' => $code, 'message' => $message], $error),
            changed: [],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $events = [];
        foreach ($this->events as $event) {
            $events[] = $event instanceof EventEnvelope ? $event->toArray() : $event;
        }

        return [
            'success' => $this->success,
            'status' => $this->status->value,
            'output' => $this->output,
            'events' => $events,
            'warnings' => $this->warnings,
            'error' => $this->error,
            'changed' => $this->changed,
        ];
    }
}
