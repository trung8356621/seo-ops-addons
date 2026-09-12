<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Social\Ai;

use Omnichannel\Addons\Social\Ai\Contracts\SocialAiTaskInterface;
use Omnichannel\Addons\Social\Ai\Exceptions\SocialAiException;
use Omnichannel\Addons\Social\Ai\Tasks\SocialCommentGenerateTask;

final class SocialAiTaskRegistry
{
    /** @var array<string, SocialAiTaskInterface> */
    private array $tasks = [];

    public function __construct()
    {
        $this->register(new SocialCommentGenerateTask());
    }

    public function register(SocialAiTaskInterface $task): void
    {
        $this->tasks[$task->taskKey()] = $task;
    }

    public function get(string $taskKey): SocialAiTaskInterface
    {
        if (! isset($this->tasks[$taskKey])) {
            throw new SocialAiException("Social AI task '{$taskKey}' không tồn tại trong registry.");
        }

        return $this->tasks[$taskKey];
    }

    public function has(string $taskKey): bool
    {
        return isset($this->tasks[$taskKey]);
    }
}
