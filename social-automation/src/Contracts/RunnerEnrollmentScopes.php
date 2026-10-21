<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Contracts;

/** @see RunnerEnrollmentContract */
final class RunnerEnrollmentScopes implements RunnerEnrollmentContract
{
    /** @return list<string> */
    public static function requiredScopes(): array
    {
        return [
            self::SCOPE_AGENT,
            self::SCOPE_SESSION,
            self::SCOPE_JOB,
        ];
    }
}
