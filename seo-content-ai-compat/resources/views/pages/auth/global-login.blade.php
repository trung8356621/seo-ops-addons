<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ __('seo-content-ai::filament.auth.login_heading') }}</title>
    <link rel="icon" href="{{ asset('favicon.ico') }}">
    <style>
        :root { color-scheme: light; }
        body { margin: 0; font-family: ui-sans-serif, system-ui, sans-serif; background: #f3f4f6; color: #111827; }
        .wrap { min-height: 100vh; display: flex; align-items: center; justify-content: center; padding: 1.5rem; }
        .card { width: 100%; max-width: 28rem; background: #fff; border-radius: 0.75rem; padding: 2rem; box-shadow: 0 1px 3px rgb(0 0 0 / 0.1); }
        .logo { display: flex; justify-content: center; margin-bottom: 1.25rem; }
        .logo img { height: 2.5rem; }
        h1 { margin: 0 0 1.5rem; font-size: 1.25rem; font-weight: 700; text-align: center; }
        label { display: block; font-size: 0.875rem; font-weight: 500; margin-bottom: 0.375rem; }
        input[type=email], input[type=password] {
            width: 100%; box-sizing: border-box; border: 1px solid #d1d5db; border-radius: 0.5rem;
            padding: 0.625rem 0.75rem; font-size: 0.875rem;
        }
        .field { margin-bottom: 1rem; }
        .remember { display: flex; align-items: center; gap: 0.5rem; margin: 1rem 0 1.25rem; font-size: 0.875rem; }
        button[type=submit] {
            width: 100%; border: 0; border-radius: 0.5rem; padding: 0.75rem 1rem; font-weight: 600;
            background: #059669; color: #fff; cursor: pointer;
        }
        button[type=submit]:hover { background: #047857; }
        .error { color: #dc2626; font-size: 0.875rem; margin: 0.35rem 0 0; }
        .divider { display: flex; align-items: center; gap: 0.75rem; margin: 1.25rem 0; color: #6b7280; font-size: 0.75rem; text-transform: uppercase; }
        .divider::before, .divider::after { content: ""; flex: 1; border-top: 1px solid #e5e7eb; }
        .google {
            display: flex; align-items: center; justify-content: center; gap: 0.5rem;
            width: 100%; box-sizing: border-box; border: 1px solid #d1d5db; border-radius: 0.5rem;
            padding: 0.75rem 1rem; text-decoration: none; color: #111827; font-weight: 600; background: #fff;
        }
    </style>
</head>
<body>
<div class="wrap">
    <div class="card">
        <div class="logo">
            <img src="{{ asset('images/logo.png') }}" alt="seo-ops">
        </div>
        <h1>{{ __('seo-content-ai::filament.auth.login_heading') }}</h1>

        <form method="post" action="{{ $action }}">
            @csrf
            @if (! empty($returnUrl))
                <input type="hidden" name="return_url" value="{{ $returnUrl }}">
            @endif

            <div class="field">
                <label for="email">{{ __('filament-panels::pages/auth/login.form.email.label') }}</label>
                <input id="email" type="email" name="data[email]" value="{{ old('data.email', old('email')) }}" required autofocus autocomplete="username">
                @error('data.email')
                    <p class="error">{{ $message }}</p>
                @enderror
            </div>

            <div class="field">
                <label for="password">{{ __('filament-panels::pages/auth/login.form.password.label') }}</label>
                <input id="password" type="password" name="data[password]" required autocomplete="current-password">
            </div>

            <label class="remember">
                <input type="checkbox" name="data[remember]" value="1" @checked(old('data.remember'))>
                {{ __('filament-panels::pages/auth/login.form.remember.label') }}
            </label>

            <button type="submit">{{ __('filament-panels::pages/auth/login.form.actions.authenticate.label') }}</button>
        </form>

        <div class="divider">Hoặc</div>

        <a class="google" href="{{ route('google.login', ['return_url' => $returnUrl ?: url('/seo')]) }}">
            Tiếp tục với Google
        </a>
    </div>
</div>
</body>
</html>
