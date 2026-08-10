<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Extension\Contracts;

interface WorkflowContributor
{
    /**
     * @return list<array{id: string, label: string}>
     */
    public function workflows(): array;
}
