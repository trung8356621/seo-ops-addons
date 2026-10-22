<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\PackageRegistry;

/**
 * @phpstan-type ReleaseArray array{
 *     package_key: string,
 *     kind: string,
 *     name: string,
 *     version: string,
 *     content_digest: string,
 *     schema_version: int,
 *     status: string,
 *     idempotent: bool
 * }
 */
final class PackagePublishResult
{
    public const OUTCOME_CREATED = 'created';

    public const OUTCOME_IDEMPOTENT = 'idempotent';

    public const OUTCOME_CONFLICT = 'conflict';

    public const OUTCOME_VALIDATION = 'validation_failed';

    public const OUTCOME_UNAVAILABLE = 'unavailable';

    public const OUTCOME_REMOVED_ON_SERVER = 'removed_on_server';

    /**
     * @param  list<string>  $errors
     * @param  ReleaseArray|null  $release
     */
    public function __construct(
        public readonly string $outcome,
        public readonly array $errors = [],
        public readonly ?array $release = null,
        public readonly ?string $existingDigest = null,
    ) {}

    public function httpStatus(): int
    {
        return match ($this->outcome) {
            self::OUTCOME_CREATED => 201,
            self::OUTCOME_IDEMPOTENT => 200,
            self::OUTCOME_CONFLICT, self::OUTCOME_REMOVED_ON_SERVER => 409,
            self::OUTCOME_VALIDATION => 422,
            self::OUTCOME_UNAVAILABLE => 503,
            default => 500,
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'ok' => in_array($this->outcome, [self::OUTCOME_CREATED, self::OUTCOME_IDEMPOTENT], true),
            'outcome' => $this->outcome,
            'errors' => $this->errors,
            'release' => $this->release,
            'existing_digest' => $this->existingDigest,
        ];
    }
}
