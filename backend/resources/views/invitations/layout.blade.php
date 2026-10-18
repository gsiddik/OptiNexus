<!DOCTYPE html>
<html lang="{{ app()->getLocale() }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <meta name="referrer" content="no-referrer">
    <title>@yield('title') · {{ __('invitations.brand') }}</title>
    <style>
        :root { color-scheme: light dark; --bg:#f4f6fa; --card:#fff; --text:#1d2433; --muted:#5b6475; --accent:#2457d6; --border:#d8dde7; --danger:#b42318; --ok:#067647; }
        @media (prefers-color-scheme: dark) { :root { --bg:#10141c; --card:#181e2a; --text:#e6e9ef; --muted:#a2abbd; --accent:#6d93ff; --border:#2b3344; --danger:#f97066; --ok:#47cd89; } }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; background:var(--bg); color:var(--text); font:15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; padding:16px; }
        .card { width:100%; max-width:440px; background:var(--card); border:1px solid var(--border); border-radius:12px; padding:28px; }
        .top { display:flex; justify-content:space-between; align-items:center; margin-bottom:4px; }
        .brand { font-weight:700; letter-spacing:.02em; color:var(--accent); }
        .lang { font-size:13px; color:var(--muted); }
        .lang a { color:var(--muted); text-decoration:none; padding:2px 4px; border-radius:4px; }
        .lang a[aria-current="true"] { color:var(--accent); font-weight:700; }
        h1 { font-size:20px; margin:8px 0 6px; }
        p { margin:0 0 12px; }
        p.lead { color:var(--muted); }
        p.action { font-weight:600; }
        label { display:block; font-weight:600; margin:14px 0 6px; }
        input[type=text], input[type=email], input[type=password] { width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:8px; background:transparent; color:inherit; font:inherit; }
        input[readonly] { color:var(--muted); }
        .hint { color:var(--muted); font-size:13px; margin:4px 0 0; }
        .notice { background:transparent; border:1px solid var(--border); border-radius:8px; padding:10px 12px; font-size:14px; color:var(--muted); margin:12px 0; }
        button, a.button { display:block; width:100%; margin-top:20px; padding:11px; border:0; border-radius:8px; background:var(--accent); color:#fff; font:inherit; font-weight:600; cursor:pointer; text-align:center; text-decoration:none; }
        .error { color:var(--danger); margin-top:6px; font-size:14px; }
        .ok { color:var(--ok); }
    </style>
</head>
<body>
<main class="card">
    <div class="top">
        <div class="brand">{{ __('invitations.brand') }}</div>
        <nav class="lang" aria-label="{{ __('invitations.language') }}">
            <a href="{{ url('/invitations/accept?lang=en') }}" @if(app()->getLocale() === 'en') aria-current="true" @endif>EN</a> |
            <a href="{{ url('/invitations/accept?lang=id') }}" @if(app()->getLocale() === 'id') aria-current="true" @endif>ID</a>
        </nav>
    </div>
    @yield('content')
</main>
</body>
</html>
