<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AgentRuntime\Integration;

use InvalidArgumentException;
use JsonException;
use Omnichannel\Addons\AgentRuntime\Catalog\AgentCapabilityCatalog;
use RuntimeException;

final class AgentIntegrationRegistry
{
    /** @var array<string, array{routing: string, cases: string}> */
    private array $registrations = [];

    /** @var array<string, mixed>|null */
    private ?array $aggregate = null;

    public function register(string $service, string $routingPath, string $casesPath): void
    {
        $service = trim($service);
        if ($service === '' || isset($this->registrations[$service])) {
            throw new InvalidArgumentException("Duplicate or empty Agent service identifier [{$service}].");
        }
        $this->registrations[$service] = ['routing' => $routingPath, 'cases' => $casesPath];
        $this->aggregate = null;
    }

    /** @return array<string, array{routing: string, cases: string}> */
    public function registrations(): array
    {
        return $this->registrations;
    }

    /** @return array<string, mixed> */
    public function document(): array
    {
        if ($this->aggregate !== null) {
            return $this->aggregate;
        }
        $document = ['revision' => 1, 'global' => [], 'modules' => [], 'lexical_hints' => [], 'policy' => []];
        $operations = [];
        foreach ($this->registrations as $service => $paths) {
            $routing = $this->readJson($paths['routing']);
            if (($routing['service'] ?? null) !== $service) {
                throw new RuntimeException("Agent routing service mismatch for [{$service}].");
            }
            foreach ((array) ($routing['operations'] ?? []) as $ref => $operation) {
                if (! is_string($ref) || isset($operations[$ref])) {
                    throw new RuntimeException("Duplicate Agent operation identifier [{$ref}].");
                }
                if (! is_array($operation)) {
                    throw new RuntimeException("Invalid Agent operation [{$ref}].");
                }
                $capability = $operation['capability'] ?? null;
                foreach (array_filter(array_merge([$capability], (array) ($operation['secondary'] ?? [])), 'is_string') as $key) {
                    if (! AgentCapabilityCatalog::known($key)) {
                        throw new RuntimeException("Agent operation [{$ref}] references unknown capability [{$key}].");
                    }
                }
                $operations[$ref] = $operation;
            }
            foreach ((array) ($routing['modules'] ?? []) as $module => $groups) {
                if (! is_string($module) || isset($document['modules'][$module])) {
                    throw new RuntimeException("Duplicate Agent module identifier [{$module}].");
                }
                $document['modules'][$module] = $groups;
            }
            $document['global'] = array_merge($document['global'], (array) ($routing['global'] ?? []));
            $document['lexical_hints'] = array_merge($document['lexical_hints'], (array) ($routing['lexical_hints'] ?? []));
            $document['policy'] = array_replace($document['policy'], (array) ($routing['policy'] ?? []));
            $document['revision'] = max($document['revision'], (int) ($routing['revision'] ?? 1));
        }
        foreach ($document['global'] as $group) {
            foreach ((array) ($group['targets'] ?? []) as $target) {
                $ref = is_array($target) ? (string) ($target['ref'] ?? '') : '';
                if (! array_key_exists($ref, $document['modules'])) throw new RuntimeException("Agent global target references unknown module [{$ref}].");
            }
        }
        foreach ($document['modules'] as $module => $groups) {
            foreach ((array) $groups as $group) {
                foreach ((array) ($group['targets'] ?? []) as $target) {
                    $ref = is_array($target) ? (string) ($target['ref'] ?? '') : '';
                    if (! isset($operations[$ref])) throw new RuntimeException("Agent module [{$module}] references unknown operation [{$ref}].");
                }
            }
        }
        $document['operations'] = $operations;

        return $this->aggregate = $document;
    }

    /** @return list<array<string, mixed>> */
    public function cases(?string $service = null): array
    {
        $cases = [];
        foreach ($this->registrations as $id => $paths) {
            if ($service !== null && $service !== $id) continue;
            $document = $this->readJson($paths['cases']);
            if (($document['service'] ?? null) !== $id) {
                throw new RuntimeException("Agent cases service mismatch for [{$id}].");
            }
            foreach ((array) ($document['cases'] ?? []) as $case) {
                if (is_array($case)) $cases[] = ['service' => $id] + $case;
            }
        }
        return $cases;
    }

    /** @return array<string, mixed> */
    private function readJson(string $path): array
    {
        $contents = @file_get_contents($path);
        if (! is_string($contents)) throw new RuntimeException("Agent integration file is not readable [{$path}].");
        if (! mb_check_encoding($contents, 'UTF-8')) throw new RuntimeException("Agent integration file is not valid UTF-8 [{$path}].");
        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("Invalid Agent integration JSON [{$path}]: {$exception->getMessage()}", 0, $exception);
        }
        if (! is_array($decoded)) throw new RuntimeException("Agent integration JSON must be an object [{$path}].");
        return $decoded;
    }
}
