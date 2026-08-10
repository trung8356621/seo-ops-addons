<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\BusinessHook\Registry;

use Omnichannel\Addons\Agent\Automation\BusinessHook\Contracts\AutomationActionHandler;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Data\AutomationActionDefinition;
use Omnichannel\Addons\Agent\Automation\BusinessHook\Enums\BusinessHookErrorCode;
use Omnichannel\Addons\Agent\Automation\Exceptions\AutomationException;
use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

final class AutomationActionRegistry
{
    /** @var array<string, AutomationActionDefinition> */
    private array $actions = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    public function register(AutomationActionDefinition $definition): void
    {
        if (isset($this->actions[$definition->actionCode])) {
            throw new InvalidArgumentException("Automation action [{$definition->actionCode}] already registered.");
        }

        $this->actions[$definition->actionCode] = $definition;
    }

    public function has(string $code): bool
    {
        return isset($this->actions[$code]);
    }

    public function get(string $code): AutomationActionDefinition
    {
        if (! isset($this->actions[$code])) {
            throw new AutomationException(
                BusinessHookErrorCode::ActionNotRegistered->value,
                "Automation action [{$code}] is not registered.",
            );
        }

        return $this->actions[$code];
    }

    /**
     * @return array<string, AutomationActionDefinition>
     */
    public function all(): array
    {
        return $this->actions;
    }

    public function resolveHandler(string $code): AutomationActionHandler
    {
        $definition = $this->get($code);
        $handler = $this->container->make($definition->handlerClass);

        if (! $handler instanceof AutomationActionHandler) {
            throw new AutomationException(
                BusinessHookErrorCode::ActionNotRegistered->value,
                "Handler for [{$code}] must implement AutomationActionHandler.",
            );
        }

        return $handler;
    }

    /**
     * @param  array<string, mixed>  $input
     * @return list<string>
     */
    public function validateInput(string $code, array $input): array
    {
        $definition = $this->get($code);
        $errors = [];

        foreach ($definition->inputRules as $field => $rule) {
            $required = (bool) ($rule['required'] ?? false);
            $exists = array_key_exists($field, $input);
            if ($required && (! $exists || $input[$field] === null || $input[$field] === '')) {
                $errors[] = "Missing required input [{$field}].";
            }
        }

        return $errors;
    }
}
