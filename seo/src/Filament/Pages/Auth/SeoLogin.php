<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Filament\Pages\Auth;

use Filament\Pages\Auth\Login;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Support\Facades\Session;

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

    protected function getRedirectUrl(): string
    {
        if (is_string($this->return_url) && $this->return_url !== '') {
            return $this->return_url;
        }

        return parent::getRedirectUrl();
    }

    public function getGoogleLoginReturnUrl(): string
    {
        if (is_string($this->return_url) && $this->return_url !== '') {
            return $this->return_url;
        }

        return filament()->getCurrentPanel()->getUrl();
    }
}
