<?php

declare(strict_types=1);

namespace Omnichannel\Addons\AiPrompt\Tests\Unit;

use PHPUnit\Framework\TestCase;

final class DomainPromptContextWordPressSyncUiContractTest extends TestCase
{
    public function test_domain_edit_form_hides_inline_wordpress_sync_actions(): void
    {
        $addonsRoot = dirname(__DIR__, 3);
        $formSrc = (string) file_get_contents($addonsRoot.'/search-foundation/src/Filament/Resources/DomainResource/Forms/DomainTechnicalSeoForm.php');
        $pageSrc = (string) file_get_contents($addonsRoot.'/search-foundation/src/Filament/Resources/DomainResource/Pages/EditDomain.php');
        $traitSrc = (string) file_get_contents($addonsRoot.'/search-foundation/src/Filament/Resources/DomainResource/Pages/Concerns/SyncsDomainPromptContextFromWordPress.php');
        $serviceSrc = (string) file_get_contents($addonsRoot.'/ai-prompt/src/Services/DomainPromptContextWordPressFieldSyncService.php');

        self::assertStringNotContainsString('sync_company_short_identity_wp', $formSrc);
        self::assertStringNotContainsString('sync_short_description_wp', $formSrc);
        self::assertStringNotContainsString('Đồng bộ WP', $formSrc);
        self::assertStringContainsString('SyncsDomainPromptContextFromWordPress', $pageSrc);
        self::assertStringContainsString('function syncCompanyShortIdentityFromWordPress', $traitSrc);
        self::assertStringContainsString('function syncShortDescriptionFromWordPress', $traitSrc);
        self::assertStringContainsString('function syncCompanyShortIdentity', $serviceSrc);
        self::assertStringContainsString('function syncShortDescription', $serviceSrc);
    }
}
