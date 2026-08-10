<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('seo-content-ai::filament.no_seo_workspace.page_title') }}</title>
    <style>
        body { font-family: system-ui, sans-serif; background: #0f172a; color: #e2e8f0; display: grid; place-items: center; min-height: 100vh; margin: 0; }
        .card { max-width: 32rem; padding: 2rem; background: #1e293b; border-radius: 1rem; box-shadow: 0 10px 40px rgba(0,0,0,.35); }
        h1 { margin: 0 0 .75rem; font-size: 1.35rem; }
        p { margin: 0; line-height: 1.6; color: #94a3b8; }
        a, .logout-link { color: #34d399; background: none; border: 0; padding: 0; font: inherit; cursor: pointer; text-decoration: underline; }
        .logout-form { display: inline; margin: 0; }
    </style>
</head>
<body>
    <div class="card">
        <h1>{{ __('seo-content-ai::filament.no_seo_workspace.heading') }}</h1>
        <p>{{ __('seo-content-ai::filament.no_seo_workspace.body') }}</p>
        <form class="logout-form" method="POST" action="{{ route('seo.logout') }}" style="margin-top: .75rem;">
            @csrf
            <button type="submit" class="logout-link">{{ __('seo-content-ai::filament.no_seo_workspace.logout') }}</button>
        </form>
    </div>
</body>
</html>
