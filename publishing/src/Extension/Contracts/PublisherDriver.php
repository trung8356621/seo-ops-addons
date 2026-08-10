<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Publishing\Extension\Contracts;

interface PublisherDriver
{
    public function id(): string;

    public function label(): string;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function publish(array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function update(array $payload): array;

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function delete(array $payload): array;

    /**
     * @param  array<string, mixed>  $query
     * @return array<string, mixed>|null
     */
    public function find(array $query): ?array;

    /**
     * @return array{ok: bool, message: string}
     */
    public function health(): array;
}
