<?php

declare(strict_types=1);

namespace Omnichannel\Addons\WordPress\Extension;

use Omnichannel\Addons\Agent\Extension\Contracts\ExtensionProvider;
use Omnichannel\Addons\Agent\Extension\ExtensionContext;

final class WordpressExtensionProvider implements ExtensionProvider
{
    public function __construct(
        private readonly WordPressPublisher $publisher,
        private readonly WordpressPublisherDriver $publisherDriver,
    ) {}

    public function id(): string
    {
        return 'wordpress';
    }

    public function register(ExtensionContext $ctx): void
    {
        $ctx->contentPublishers()->register($this->id(), $this->publisher);
        $ctx->publishers()->register($this->id(), $this->publisherDriver);
    }

    public function boot(ExtensionContext $ctx): void
    {
        unset($ctx);
    }
}
