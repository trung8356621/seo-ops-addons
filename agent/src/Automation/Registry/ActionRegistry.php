<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Registry;

use Omnichannel\Addons\Agent\Automation\Contracts\BusinessAction;
use Omnichannel\Addons\Agent\Automation\Data\ActionDefinition;
use Omnichannel\Addons\Agent\Automation\Exceptions\AutomationException;
use Omnichannel\Addons\Agent\Automation\Support\CanonicalIds;
use Illuminate\Contracts\Container\Container;

final class ActionRegistry
{
    /** @var array<string, ActionDefinition> */
    private array $definitions = [];

    /** @var array<string, class-string<BusinessAction>> */
    private array $handlers = [];

    public function __construct(
        private readonly Container $container,
    ) {}

    /**
     * @param  class-string<BusinessAction>  $handlerClass
     */
    public function registerHandler(string $handlerClass): void
    {
        if (! is_subclass_of($handlerClass, BusinessAction::class)) {
            throw AutomationException::invalidHandler($handlerClass);
        }

        $definition = $handlerClass::definition();
        CanonicalIds::assertActionKey($definition->key);

        if (isset($this->handlers[$definition->key])) {
            throw AutomationException::duplicateKey($definition->key);
        }

        // Handler definition wins over catalog-only metadata for the same key.
        $this->definitions[$definition->key] = $definition;
        $this->handlers[$definition->key] = $handlerClass;
    }

    public function registerDefinition(ActionDefinition $definition): void
    {
        CanonicalIds::assertActionKey($definition->key);
        $this->putDefinition($definition);
    }

    public function has(string $key): bool
    {
        return isset($this->definitions[$key]);
    }

    public function hasHandler(string $key): bool
    {
        return isset($this->handlers[$key]);
    }

    public function definition(string $key): ActionDefinition
    {
        if (! isset($this->definitions[$key])) {
            throw AutomationException::actionNotFound($key);
        }

        return $this->definitions[$key];
    }

    /**
     * @return array<string, ActionDefinition>
     */
    public function definitions(): array
    {
        return $this->definitions;
    }

    /**
     * @return list<string>
     */
    public function selectableKeys(): array
    {
        $keys = [];
        foreach ($this->definitions as $key => $definition) {
            if ($definition->isSelectableForWorkflow()) {
                $keys[] = $key;
            }
        }

        return $keys;
    }

    public function get(string $key): BusinessAction
    {
        if (! isset($this->handlers[$key])) {
            if (! isset($this->definitions[$key])) {
                throw AutomationException::actionNotFound($key);
            }

            throw AutomationException::handlerMissing($key);
        }

        $handler = $this->container->make($this->handlers[$key]);
        if (! $handler instanceof BusinessAction) {
            throw AutomationException::invalidHandler($this->handlers[$key]);
        }

        return $handler;
    }

    /**
     * Validate input against definition schema (required + basic type).
     *
     * @param  array<string, mixed>  $input
     * @return list<string> error messages (empty = ok)
     */
    public function validate(string $key, array $input): array
    {
        $definition = $this->definition($key);
        $errors = [];

        foreach ($definition->inputSchema as $field => $rules) {
            if (! is_array($rules)) {
                continue;
            }

            $required = (bool) ($rules['required'] ?? false);
            $exists = array_key_exists($field, $input) && $input[$field] !== null && $input[$field] !== '';

            if ($required && ! $exists) {
                $errors[] = "Missing required field [{$field}].";

                continue;
            }

            if (! $exists) {
                continue;
            }

            $type = (string) ($rules['type'] ?? '');
            $value = $input[$field];
            $ok = match ($type) {
                'integer' => is_int($value) || (is_string($value) && ctype_digit($value)),
                'string' => is_string($value),
                'boolean' => is_bool($value),
                'array' => is_array($value),
                'number' => is_int($value) || is_float($value) || (is_string($value) && is_numeric($value)),
                '' => true,
                default => true,
            };

            if (! $ok) {
                $errors[] = "Field [{$field}] must be of type [{$type}].";
            }
        }

        return $errors;
    }

    private function putDefinition(ActionDefinition $definition): void
    {
        if (isset($this->definitions[$definition->key])) {
            throw AutomationException::duplicateKey($definition->key);
        }

        $this->definitions[$definition->key] = $definition;
    }
}
