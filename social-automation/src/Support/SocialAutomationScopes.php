<?php

declare(strict_types=1);

namespace Omnichannel\Addons\SocialAutomation\Support;

/**
 * Service API scopes for Social Automation callers.
 * Ops-server provisioned Service secret is never a Bearer credential.
 */
final class SocialAutomationScopes
{
    public const PACKAGE_READ = 'social-automation:package:read';

    public const PACKAGE_PUBLISH = 'social-automation:package:publish';

    public const ADMIN_TASK = 'social-automation:admin:task';

    public const ADMIN_DISPATCH = 'social-automation:admin:dispatch';

    public const RUNNER_AGENT = 'social-automation:runner:agent';

    public const RUNNER_SESSION = 'social-automation:runner:session';

    public const RUNNER_JOB = 'social-automation:runner:job';

    /** @return list<string> */
    public static function all(): array
    {
        return [
            self::PACKAGE_READ,
            self::PACKAGE_PUBLISH,
            self::ADMIN_TASK,
            self::ADMIN_DISPATCH,
            self::RUNNER_AGENT,
            self::RUNNER_SESSION,
            self::RUNNER_JOB,
        ];
    }
}
