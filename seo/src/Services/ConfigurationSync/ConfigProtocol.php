<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\ConfigurationSync;

/**
 * seo-ops-config/1. Distinct from seo-ops-license/1 so signatures cannot cross endpoints.
 */
final class ConfigProtocol
{
    public const ID = 'seo-ops-config/1';

    public const SERVICE_KEY = 'seo';

    public const PACKAGE_ID = 'seo-ops-golden-configuration';

    public const EMPTY_BODY_SHA256 = '47DEQpj8HBSa-_TImW-5JCeuQeRkm5NMpJWZG3hSuFU';

    /** @var list<string> */
    public const ALLOWED_FILES = [
        'manifest.json',
        'metadata/checksums.json',
        'metadata/prompt-index.json',
        'metadata/workflow-index.json',
        'payload/full-configuration.json',
        'payload/settings.json',
        'payload/prompts.json',
        'payload/workflows.json',
        'payload/provider-templates.json',
    ];

    /**
     * G1 runtime returns status "duplicate" for idempotent replay and identical canonical hash.
     * protocol.md section 7 still says "applied". Both are successful ACKs.
     *
     * @var list<string>
     */
    public const ACCEPTED_RECEIPT_STATUSES = ['applied', 'duplicate'];
}
