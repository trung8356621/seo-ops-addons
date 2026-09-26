<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Services\Context\Slice\Contracts;

use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSlice;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceDefinition;
use Omnichannel\Addons\Seo\Services\Context\Slice\ContextSliceRequest;

interface ContextSliceProvider
{
    public function definition(): ContextSliceDefinition;

    public function provide(ContextSliceRequest $request): ContextSlice;
}
