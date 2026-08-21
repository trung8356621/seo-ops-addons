<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Seo\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\User;
use Filament\Facades\Filament;
use Filament\Models\Contracts\FilamentUser;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Omnichannel\Addons\Seo\Services\SeoLoginServiceResolver;
use Omnichannel\Addons\Seo\Support\SeoConnectionContext;

/**
 * Classic GET/POST auth for short /seo/login and POST fallback for hash login.
 * Reuses Filament auth guard + panel access checks (same as Login::authenticate).
 */
final class SeoLoginController extends Controller
{
    public function __construct(
        private readonly SeoLoginServiceResolver $loginServiceResolver,
    ) {}

    public function create(Request $request): View|RedirectResponse
    {
        if (auth()->check()) {
            Filament::setCurrentPanel(Filament::getPanel('seo'));

            /** @var User $user */
            $user = auth()->user();

            return redirect()->to(
                $this->loginServiceResolver->redirectUrlAfterLogin(
                    $user,
                    null,
                    $this->intendedFromRequest($request),
                ),
            );
        }

        return view('seo-content-ai::pages.auth.global-login', [
            'action' => route('seo.auth.login.store'),
            'returnUrl' => $request->query('return_url'),
        ]);
    }

    public function store(Request $request, ?string $connection_hash = null): RedirectResponse
    {
        Filament::setCurrentPanel(Filament::getPanel('seo'));
        Filament::bootCurrentPanel();

        $this->ensureIsNotRateLimited($request);

        $data = $this->validatedCredentials($request);

        if (! Filament::auth()->attempt(
            [
                'email' => $data['email'],
                'password' => $data['password'],
            ],
            $data['remember'],
        )) {
            RateLimiter::hit($this->throttleKey($request));

            throw ValidationException::withMessages([
                'data.email' => __('filament-panels::pages/auth/login.messages.failed'),
            ]);
        }

        /** @var User $user */
        $user = Filament::auth()->user();
        $panel = Filament::getCurrentPanel() ?? Filament::getPanel('seo');

        if (($user instanceof FilamentUser) && (! $user->canAccessPanel($panel))) {
            Filament::auth()->logout();
            RateLimiter::hit($this->throttleKey($request));

            throw ValidationException::withMessages([
                'data.email' => __('filament-panels::pages/auth/login.messages.failed'),
            ]);
        }

        RateLimiter::clear($this->throttleKey($request));
        $request->session()->regenerate();

        $explicitHash = is_string($connection_hash) && SeoConnectionContext::isValidHashFormat($connection_hash)
            ? $connection_hash
            : null;

        return redirect()->to(
            $this->loginServiceResolver->redirectUrlAfterLogin(
                $user,
                $explicitHash,
                $this->intendedFromRequest($request),
            ),
        );
    }

    private function ensureIsNotRateLimited(Request $request): void
    {
        if (! RateLimiter::tooManyAttempts($this->throttleKey($request), 5)) {
            return;
        }

        $seconds = RateLimiter::availableIn($this->throttleKey($request));

        throw ValidationException::withMessages([
            'data.email' => __('filament-panels::pages/auth/login.notifications.throttled.title', [
                'seconds' => $seconds,
                'minutes' => (int) ceil($seconds / 60),
            ]),
        ]);
    }

    private function throttleKey(Request $request): string
    {
        $email = strtolower((string) ($request->input('data.email') ?? $request->input('email') ?? ''));

        return 'seo-login:'.$email.'|'.$request->ip();
    }

    /**
     * @return array{email: string, password: string, remember: bool}
     */
    private function validatedCredentials(Request $request): array
    {
        $payload = $request->validate([
            'email' => ['sometimes', 'string', 'email'],
            'password' => ['sometimes', 'string'],
            'remember' => ['sometimes', 'boolean'],
            'data.email' => ['sometimes', 'string', 'email'],
            'data.password' => ['sometimes', 'string'],
            'data.remember' => ['sometimes', 'boolean'],
        ]);

        $email = (string) ($payload['data']['email'] ?? $payload['email'] ?? '');
        $password = (string) ($payload['data']['password'] ?? $payload['password'] ?? '');
        $remember = (bool) ($payload['data']['remember'] ?? $payload['remember'] ?? false);

        if ($email === '' || $password === '') {
            throw ValidationException::withMessages([
                'data.email' => __('filament-panels::pages/auth/login.messages.failed'),
            ]);
        }

        return [
            'email' => $email,
            'password' => $password,
            'remember' => $remember,
        ];
    }

    private function intendedFromRequest(Request $request): ?string
    {
        $returnUrl = $request->input('return_url', $request->query('return_url'));
        if (is_string($returnUrl) && $returnUrl !== '') {
            return $returnUrl;
        }

        $intended = $request->session()->pull('url.intended');

        return is_string($intended) && $intended !== '' ? $intended : null;
    }
}
