<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\SectionedFree;

/**
 * Parent orchestrator breadcrumbs for sectioned_free runs.
 *
 * @phpstan-type Breadcrumb array{at: string, event: string, data?: array<string, mixed>}
 */
final class SectionedFreeBreadcrumbBag
{
    /** @var list<Breadcrumb> */
    private array $events = [];

    /**
     * @param  array<string, mixed>  $data
     */
    public function push(string $event, array $data = []): void
    {
        $row = [
            'at' => gmdate('c'),
            'event' => $event,
        ];
        if ($data !== []) {
            $row['data'] = $data;
        }
        $this->events[] = $row;
    }

    /**
     * @return list<Breadcrumb>
     */
    public function all(): array
    {
        return $this->events;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'breadcrumbs' => $this->events,
            'last_event' => $this->events !== []
                ? $this->events[array_key_last($this->events)]['event']
                : null,
        ];
    }
}
