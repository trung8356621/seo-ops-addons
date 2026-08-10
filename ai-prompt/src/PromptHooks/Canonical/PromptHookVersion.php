<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\PromptHooks\Canonical;

/** SemVer pin — experimental must never resolve "latest". */
final class PromptHookVersion
{
    public function __construct(
        public readonly int $major,
        public readonly int $minor,
        public readonly int $patch,
    ) {
        if ($major < 0 || $minor < 0 || $patch < 0) {
            throw new \InvalidArgumentException('SemVer components must be non-negative.');
        }
    }

    public static function parse(string|int $raw): self
    {
        if (is_int($raw)) {
            return new self($raw, 0, 0);
        }

        $trimmed = trim($raw);
        if (preg_match('/^(\d+)\.(\d+)\.(\d+)$/', $trimmed, $m) === 1) {
            return new self((int) $m[1], (int) $m[2], (int) $m[3]);
        }
        if (preg_match('/^(\d+)\.(\d+)$/', $trimmed, $m) === 1) {
            return new self((int) $m[1], (int) $m[2], 0);
        }
        if (preg_match('/^(\d+)$/', $trimmed, $m) === 1) {
            return new self((int) $m[1], 0, 0);
        }

        throw new \InvalidArgumentException("Invalid SemVer [{$raw}]");
    }

    public function toString(): string
    {
        return "{$this->major}.{$this->minor}.{$this->patch}";
    }

    public function equals(self $other): bool
    {
        return $this->major === $other->major
            && $this->minor === $other->minor
            && $this->patch === $other->patch;
    }

    public function __toString(): string
    {
        return $this->toString();
    }
}
