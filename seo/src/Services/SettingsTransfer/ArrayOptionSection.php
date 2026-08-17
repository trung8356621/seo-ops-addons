<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\SettingsTransfer;

final class ArrayOptionSection implements PortableSettingsSection
{
    /**
     * @param  list<string>  $allowedKeys
     * @param  \Closure(): array<string, mixed>  $loader
     * @param  \Closure(array<string, mixed>): void  $saver
     */
    public function __construct(
        private readonly string $sectionKey,
        private readonly array $allowedKeys,
        private readonly \Closure $loader,
        private readonly \Closure $saver,
    ) {}

    public function key(): string
    {
        return $this->sectionKey;
    }

    public function export(int $userId): array
    {
        unset($userId);
        $raw = ($this->loader)();
        $out = [];
        foreach ($this->allowedKeys as $key) {
            if (array_key_exists($key, $raw)) {
                $out[$key] = $raw[$key];
            }
        }

        return $out;
    }

    public function diff(int $userId, array $incoming): array
    {
        $current = $this->export($userId);
        $filtered = $this->filter($incoming);
        $lines = [];
        $changed = 0;
        $unchanged = 0;
        foreach ($filtered as $key => $value) {
            $before = $current[$key] ?? null;
            if ($this->same($before, $value)) {
                $unchanged++;
            } else {
                $changed++;
                $lines[] = $key.': '.$this->preview($before).' → '.$this->preview($value);
            }
        }

        return [
            'changed' => $changed,
            'unchanged' => $unchanged,
            'lines' => $lines,
            'warnings' => [],
            'payload' => $filtered,
        ];
    }

    public function apply(int $userId, array $incoming, string $mode): void
    {
        unset($userId);
        $filtered = $this->filter($incoming);
        if ($mode === 'replace') {
            ($this->saver)($filtered);

            return;
        }
        $current = $this->export(0);
        ($this->saver)(array_merge($current, $filtered));
    }

    /**
     * @param  array<string, mixed>  $incoming
     * @return array<string, mixed>
     */
    private function filter(array $incoming): array
    {
        $out = [];
        foreach ($this->allowedKeys as $key) {
            if (array_key_exists($key, $incoming)) {
                $out[$key] = $incoming[$key];
            }
        }

        return $out;
    }

    private function same(mixed $a, mixed $b): bool
    {
        return json_encode($a) === json_encode($b);
    }

    private function preview(mixed $value): string
    {
        if (is_scalar($value) || $value === null) {
            return mb_substr((string) $value, 0, 80);
        }

        return mb_substr((string) json_encode($value), 0, 80);
    }
}
