<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DomainPromptContextWordPressSyncUiContractTest extends TestCase
{
    public function test_domain_edit_form_renders_inline_wordpress_sync_actions(): void
    {
        $addonsRoot = dirname(__DIR__, 3);
        $formSrc = (string) file_get_contents($addonsRoot.'/search-foundation/src/Filament/Resources/DomainResource/Forms/DomainTechnicalSeoForm.php');
        $pageSrc = (string) file_get_contents($addonsRoot.'/search-foundation/src/Filament/Resources/DomainResource/Pages/EditDomain.php');
        $traitSrc = (string) file_get_contents($addonsRoot.'/search-foundation/src/Filament/Resources/DomainResource/Pages/Concerns/SyncsDomainPromptContextFromWordPress.php');

        self::assertStringContainsString('sync_company_short_identity_wp', $formSrc);
        self::assertStringContainsString('sync_short_description_wp', $formSrc);
        self::assertStringContainsString('Đồng bộ WP', $formSrc);
        self::assertStringContainsString('heroicon-o-arrow-path', $formSrc);
        self::assertStringContainsString('syncCompanyShortIdentityFromWordPress', $formSrc);
        self::assertStringContainsString('syncShortDescriptionFromWordPress', $formSrc);
        self::assertStringContainsString('syncingCompanyShortIdentityFromWp', $formSrc);
        self::assertStringContainsString('syncingShortDescriptionFromWp', $formSrc);
        self::assertStringContainsString('SyncsDomainPromptContextFromWordPress', $pageSrc);
        self::assertStringContainsString('Đang đồng bộ...', $formSrc);
        self::assertStringContainsString('Đã đồng bộ Tiêu đề website từ WordPress.', $traitSrc);
        self::assertStringContainsString('Đã đồng bộ Dòng mô tả từ WordPress.', $traitSrc);
    }
}
