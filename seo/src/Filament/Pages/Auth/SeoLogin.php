<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Pages\Auth;

use App\Models\User;
use Filament\Facades\Filament;
use Filament\Pages\Auth\Login;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Session;
use Omnichannel\Addons\Seo\Services\SeoLoginServiceResolver;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;

final class SeoLogin extends Login
{
    public ?string $return_url = null;

    protected static string $view = 'filament.pages.auth.login';

    public function getHeading(): string|Htmlable
    {
        return __('seo-content-ai::filament.auth.login_heading');
    }

    public function mount(): void
    {
        parent::mount();

        $emailFromUrl = request()->query('email');
        if (is_string($emailFromUrl) && $emailFromUrl !== '') {
            $this->form->fill([
                'email' => $emailFromUrl,
                'remember' => true,
            ]);
        }

        $returnUrl = request()->query('return_url');
        if (is_string($returnUrl) && $returnUrl !== '') {
            $this->return_url = $returnUrl;
            Session::put('url.intended', $returnUrl);
        }
    }

    public function getLoginFormActionUrl(): string
    {
        $hash = request()->route('connection_hash');
        if (is_string($hash) && SeoConnectionContext::isValidHashFormat($hash)) {
            return route('seo.auth.login.hash.store', ['connection_hash' => $hash]);
        }

        return route('seo.auth.login.store');
    }

    protected function getRedirectUrl(): string
    {
        if (is_string($this->return_url) && $this->return_url !== '') {
            return $this->return_url;
        }

        $user = Filament::auth()->user();
        if (! $user instanceof User) {
            return parent::getRedirectUrl();
        }

        $explicitHash = request()->route('connection_hash');
        $explicitHash = is_string($explicitHash) && SeoConnectionContext::isValidHashFormat($explicitHash)
            ? $explicitHash
            : null;

        return app(SeoLoginServiceResolver::class)->redirectUrlAfterLogin($user, $explicitHash);
    }

    public function getGoogleLoginReturnUrl(): string
    {
        if (is_string($this->return_url) && $this->return_url !== '') {
            return $this->return_url;
        }

        $hash = request()->route('connection_hash');
        if (is_string($hash) && SeoConnectionContext::isValidHashFormat($hash)) {
            return url('/seo/'.$hash);
        }

        return url('/seo');
    }
}
