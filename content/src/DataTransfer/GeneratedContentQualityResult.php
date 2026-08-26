<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Content\DataTransfer;

/**
 * @phpstan-type QualityIssue array{
 *     rule: string,
 *     severity: 'warning'|'reject',
 *     sample: string,
 *     context?: string
 * }
 */
final readonly class GeneratedContentQualityResult
{
    public const SEVERITY_WARNING = 'warning';

    public const SEVERITY_REJECT = 'reject';

    /**
     * @param  list<QualityIssue>  $issues
     */
    public function __construct(
        public bool $passed,
        public array $issues = [],
    ) {}

    public static function pass(): self
    {
        return new self(passed: true, issues: []);
    }

    /**
     * @param  list<QualityIssue>  $issues
     */
    public static function fromIssues(array $issues): self
    {
        foreach ($issues as $issue) {
            if (($issue['severity'] ?? '') === self::SEVERITY_REJECT) {
                return new self(passed: false, issues: $issues);
            }
        }

        return new self(passed: true, issues: $issues);
    }

    /**
     * @return list<string>
     */
    public function rejectRules(): array
    {
        $rules = [];
        foreach ($this->issues as $issue) {
            if (($issue['severity'] ?? '') === self::SEVERITY_REJECT) {
                $rules[] = (string) ($issue['rule'] ?? '');
            }
        }

        return array_values(array_filter($rules));
    }

    public function primarySample(): string
    {
        foreach ($this->issues as $issue) {
            if (($issue['severity'] ?? '') === self::SEVERITY_REJECT) {
                return (string) ($issue['sample'] ?? '');
            }
        }

        return (string) (($this->issues[0]['sample'] ?? '') ?: '');
    }
}
