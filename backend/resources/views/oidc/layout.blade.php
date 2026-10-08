<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'OptiNexus') · OptiNexus</title>
    <style>
        :root { color-scheme: light dark; --bg:#f4f6fa; --card:#fff; --text:#1d2433; --muted:#5b6475; --accent:#2457d6; --border:#d8dde7; --danger:#b42318; }
        @media (prefers-color-scheme: dark) { :root { --bg:#10141c; --card:#181e2a; --text:#e6e9ef; --muted:#a2abbd; --accent:#6d93ff; --border:#2b3344; --danger:#f97066; } }
        * { box-sizing: border-box; }
        body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center; background:var(--bg); color:var(--text); font:15px/1.5 system-ui, -apple-system, "Segoe UI", sans-serif; padding:16px; }
        .card { width:100%; max-width:400px; background:var(--card); border:1px solid var(--border); border-radius:12px; padding:28px; }
        .brand { font-weight:700; letter-spacing:.02em; color:var(--accent); margin-bottom:4px; }
        h1 { font-size:20px; margin:0 0 6px; }
        p.lead { color:var(--muted); margin:0 0 20px; }
        label { display:block; font-weight:600; margin:14px 0 6px; }
        input[type=email], input[type=password] { width:100%; padding:10px 12px; border:1px solid var(--border); border-radius:8px; background:transparent; color:inherit; font:inherit; }
        button { width:100%; margin-top:20px; padding:11px; border:0; border-radius:8px; background:var(--accent); color:#fff; font:inherit; font-weight:600; cursor:pointer; }
        .error { color:var(--danger); margin-top:8px; font-size:14px; }
        .tenant { display:block; width:100%; text-align:left; margin-top:10px; background:transparent; color:inherit; border:1px solid var(--border); }
        .tenant small { display:block; color:var(--muted); font-weight:400; }
    </style>
</head>
<body>
<main class="card">
    <div class="brand">OptiNexus</div>
    @yield('content')
</main>
</body>
</html>
