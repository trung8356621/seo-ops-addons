<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Platform\Registry;

use Omnichannel\Addons\Agent\Automation\Platform\Data\ConditionOperatorDefinition;
use InvalidArgumentException;

final class AutomationConditionRegistry
{
    /** @var array<string, ConditionOperatorDefinition> */
    private array $operators = [];

    /** @var list<string> */
    private array $fieldRoots = [];

    public function registerOperator(ConditionOperatorDefinition $definition): void
    {
        if (isset($this->operators[$definition->name])) {
            throw new InvalidArgumentException("Condition operator [{$definition->name}] already registered.");
        }

        $this->operators[$definition->name] = $definition;
    }

    /**
     * @param  list<string>  $roots
     */
    public function registerFieldRoots(string $module, array $roots): void
    {
        foreach ($roots as $root) {
            if (! in_array($root, $this->fieldRoots, true)) {
                $this->fieldRoots[] = $root;
            }
        }
    }

    public function hasOperator(string $name): bool
    {
        return isset($this->operators[$name]);
    }

    public function getOperator(string $name): ConditionOperatorDefinition
    {
        if (! isset($this->operators[$name])) {
            throw new InvalidArgumentException("Condition operator [{$name}] is not registered.");
        }

        return $this->operators[$name];
    }

    /**
     * @return array<string, ConditionOperatorDefinition>
     */
    public function operators(): array
    {
        return $this->operators;
    }

    /**
     * @return list<string>
     */
    public function extraFieldRoots(): array
    {
        return $this->fieldRoots;
    }
}
