<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Extension;

final class ExtensionEvents
{
    public const PROJECT_CREATED = 'content_project.created.v1';

    public const ITEMS_GENERATED = 'content_project.generated.v1';

    public const PUBLISHED = 'article.published.v1';

    public const ARCHIVED = 'content_project.archived.v1';

    public const EXTENSION_ENABLED = 'extension.enabled.v1';

    public const EXTENSION_DISABLED = 'extension.disabled.v1';
}
