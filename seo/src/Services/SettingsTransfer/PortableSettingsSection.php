<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\SettingsTransfer;

interface PortableSettingsSection
{
    public function key(): string;

    /**
     * @return array<string, mixed>
     */
    public function export(int $userId): array;

    /**
     * @param  array<string, mixed>  $incoming
     * @return array{changed: int, unchanged: int, lines: list<string>, warnings: list<string>, payload: array<string, mixed>}
     */
    public function diff(int $userId, array $incoming): array;

    /**
     * @param  array<string, mixed>  $incoming
     */
    public function apply(int $userId, array $incoming, string $mode): void;
}
