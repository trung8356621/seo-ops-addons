<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seeding\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Manager Social Accounts foundation — DB/API/UI/security contracts.
 * Manager Social Accounts are the source for Website Share target snapshots.
 */
final class SeedingSocialAccountsContractTest extends TestCase
{
    private function addonRoot(): string
    {
        return dirname(__DIR__, 2);
    }

    private function src(string $relative): string
    {
        return (string) file_get_contents($this->addonRoot().'/src/'.$relative);
    }

    private function js(string $relative): string
    {
        return (string) file_get_contents($this->addonRoot().'/resources/js/seeding/'.$relative);
    }

    public function test_migration_on_omi_seeding_with_encrypted_columns(): void
    {
        $path = $this->addonRoot().'/database/migrations/2026_09_25_110000_create_seeding_social_accounts_table.php';
        self::assertFileExists($path);
        $sql = (string) file_get_contents($path);
        self::assertStringContainsString("protected \$connection = 'omi_seeding'", $sql);
        self::assertStringContainsString('seeding_social_accounts', $sql);
        self::assertStringContainsString('installation_id', $sql);
        self::assertStringContainsString('site_id', $sql);
        self::assertStringContainsString('domain', $sql);
        self::assertStringContainsString('platform', $sql);
        self::assertStringContainsString('username_encrypted', $sql);
        self::assertStringContainsString('password_encrypted', $sql);
        self::assertStringContainsString('status', $sql);
        self::assertStringContainsString('meta_json', $sql);
        self::assertStringContainsString('credential_updated_at', $sql);
        self::assertStringNotContainsString('foreignId', $sql);
        self::assertStringNotContainsString('constrained(', $sql);
    }

    public function test_status_enum_is_active_locked_only(): void
    {
        $enum = $this->src('Enums/SeedingSocialAccountStatus.php');
        self::assertStringContainsString("case Active = 'active'", $enum);
        self::assertStringContainsString("case Locked = 'locked'", $enum);
        self::assertSame(2, substr_count($enum, 'case '));
    }

    public function test_model_hides_secrets_and_uses_laravel_encrypted_cast(): void
    {
        $model = $this->src('Models/SeedingSocialAccount.php');
        self::assertStringContainsString("'username_encrypted' => 'encrypted'", $model);
        self::assertStringContainsString("'password_encrypted' => 'encrypted'", $model);
        self::assertStringContainsString("'username_encrypted'", $model);
        self::assertStringContainsString('protected $hidden', $model);
        self::assertStringContainsString('toManagerApiArray', $model);
        self::assertStringContainsString('toActiveReadArray', $model);
        self::assertStringContainsString('has_password', $model);
        self::assertStringNotContainsString("'password'", $model);
        // Manager API must not expose password key.
        $start = strpos($model, 'function toManagerApiArray');
        self::assertNotFalse($start);
        $body = substr($model, $start, 1800);
        self::assertStringNotContainsString("'password'", $body);
        self::assertStringContainsString("'has_password'", $body);
        self::assertStringContainsString("'username'", $body);

        $activeStart = strpos($model, 'function toActiveReadArray');
        self::assertNotFalse($activeStart);
        $activeBody = substr($model, $activeStart, 900);
        self::assertStringNotContainsString('username', $activeBody);
        self::assertStringNotContainsString('password', $activeBody);
    }

    public function test_service_active_read_model_and_blank_password_preserve(): void
    {
        $service = $this->src('Services/SeedingSocialAccountService.php');
        self::assertStringContainsString('function activeForWorkspace', $service);
        self::assertStringContainsString('function activeForSite', $service);
        self::assertStringContainsString('->active()', $service);
        self::assertStringContainsString('installationNamespace', $service);
        self::assertStringContainsString('Blank password on update', $service);
        self::assertStringContainsString('function copyPassword', $service);
        self::assertStringContainsString('function copyUsername', $service);
        self::assertStringContainsString('function lock', $service);
        self::assertStringContainsString('function unlock', $service);
        self::assertStringContainsString('SeedingSocialPlatform', $service);
        self::assertStringContainsString('assertCanAccessSite', $service);
        // Must not touch Website Share defaults from this service.
        self::assertStringNotContainsString('DEFAULT_SOCIALS', $service);
        self::assertStringNotContainsString('WebsiteShareJobService', $service);
    }

    public function test_provider_registers_manager_routes_and_singleton(): void
    {
        $provider = $this->src('SeedingServiceProvider.php');
        self::assertStringContainsString('SeedingManagerSocialAccountsController', $provider);
        self::assertStringContainsString('SeedingSocialAccountService', $provider);
        self::assertStringContainsString("'/manager/social-accounts'", $provider);
        self::assertStringContainsString("'/manager/social-accounts/{accountId}/lock'", $provider);
        self::assertStringContainsString("'/manager/social-accounts/{accountId}/unlock'", $provider);
        self::assertStringContainsString("'/manager/social-accounts/{accountId}/copy-username'", $provider);
        self::assertStringContainsString("'/manager/social-accounts/{accountId}/copy-password'", $provider);
        self::assertStringContainsString('seeding.manager.social-accounts.index', $provider);
    }

    public function test_controller_is_manager_gated_on_every_action(): void
    {
        $controller = $this->src('Http/Controllers/SeedingManagerSocialAccountsController.php');
        self::assertSame(8, substr_count($controller, 'assertCanManage'));
        self::assertStringContainsString('function copyPassword', $controller);
        self::assertStringContainsString('function copyUsername', $controller);
        self::assertStringContainsString("'value' => \$value", $controller);
        self::assertStringContainsString('toManagerApiArray()', $controller);
        // Copy endpoints expose `value`, never a password JSON key.
        self::assertStringNotContainsString("'password' => \$value", $controller);
        self::assertStringNotContainsString("'password' => \$row", $controller);
    }

    public function test_website_share_uses_active_social_account_resolver(): void
    {
        $share = $this->src('Services/WebsiteShareJobService.php');
        $service = $this->src('Services/SeedingSocialAccountService.php');
        self::assertStringNotContainsString('DEFAULT_SOCIALS', $share);
        self::assertStringContainsString('activePlatformsForWebsiteShare', $service);
        self::assertStringContainsString('activePlatformsForWebsiteShare', $share);
        self::assertStringContainsString("where('site_id', \$siteId)", $service);
        self::assertStringContainsString('normalizeDomain', $service);
        self::assertStringContainsString('->unique()', $service);
    }

    public function test_api_js_exposes_social_account_helpers(): void
    {
        $api = $this->js('api.js');
        self::assertStringContainsString('fetchSocialAccounts', $api);
        self::assertStringContainsString('createSocialAccount', $api);
        self::assertStringContainsString('updateSocialAccount', $api);
        self::assertStringContainsString('lockSocialAccount', $api);
        self::assertStringContainsString('unlockSocialAccount', $api);
        self::assertStringContainsString('deleteSocialAccount', $api);
        self::assertStringContainsString('copySocialAccountUsername', $api);
        self::assertStringContainsString('copySocialAccountPassword', $api);
        self::assertStringContainsString('/api/seeding/manager/social-accounts', $api);
    }

    public function test_manager_ui_social_accounts_tab_and_copy_contract(): void
    {
        $panel = $this->js('components/ManagerPanel.jsx');
        $social = $this->js('components/SocialAccountsPanel.jsx');
        $css = (string) file_get_contents($this->addonRoot().'/resources/css/seeding-workspace.css');

        self::assertStringContainsString("label: 'Tài khoản Social'", $panel);
        self::assertStringContainsString("subTab === 'social-accounts'", $panel);
        self::assertStringContainsString('SocialAccountsPanel', $panel);

        self::assertStringContainsString('data-section="manager-social-accounts"', $social);
        self::assertStringContainsString('writeClipboard', $social);
        self::assertStringContainsString("from '../services/clipboardWrite'", $social);
        self::assertStringContainsString('Copy ID', $social);
        self::assertStringContainsString('Copy password', $social);
        self::assertStringContainsString('••••••••', $social);
        self::assertStringContainsString('leave blank to keep current', $social);
        self::assertStringContainsString('data-action="copy-password"', $social);
        self::assertStringContainsString("data-action={isLocked ? 'unlock' : 'lock'}", $social);
        self::assertStringContainsString('Unlock', $social);
        self::assertStringContainsString('Lock', $social);
        self::assertStringNotContainsString('Show password', $social);
        self::assertStringNotContainsString('showPassword', $social);
        self::assertStringNotContainsString('type="text"', $social);

        // Password plaintext must not be stored in React state after copy.
        self::assertStringContainsString('const wrote = await writeClipboard(value)', $social);
        self::assertDoesNotMatchRegularExpression(
            '/set[A-Z]\w*\(\s*value\s*\)/',
            $social,
        );

        self::assertStringContainsString('seeding-ws__social-acct-card', $css);
        self::assertStringContainsString('seeding-ws__social-acct-domain', $css);
    }

    public function test_platform_reuses_seeding_social_platform_enum(): void
    {
        $model = $this->src('Models/SeedingSocialAccount.php');
        $service = $this->src('Services/SeedingSocialAccountService.php');
        self::assertStringContainsString('SeedingSocialPlatform::class', $model);
        self::assertStringContainsString('SeedingSocialPlatform::tryFromLabelOrValue', $service);
        self::assertFileDoesNotExist($this->addonRoot().'/src/Enums/SocialPlatform.php');
    }
}
