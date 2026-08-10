<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Agent\Automation\Enums;

/**
 * selectable = workflow/rule được tham chiếu (Phase 3+ UI).
 * internal_only = chỉ code nội bộ.
 * legacy_not_selectable = catalog migrate; cấm expose workflow/UI.
 */
enum ActionSelectability: string
{
    case Selectable = 'selectable';
    case InternalOnly = 'internal_only';
    case LegacyNotSelectable = 'legacy_not_selectable';
}
