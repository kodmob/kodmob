<!doctype html>
<html lang="tr">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex, nofollow">
    <title>Kodmob kurulum</title>
    <style>
        :root { --bg: #f5f6f8; --card: #fff; --ink: #14171f; --muted: #5d6475; --line: #e3e6ec; --ok: #0f7b4f; --bad: #b42318; --warn: #a15c07; --brand: #4f46e5; }
        * { box-sizing: border-box; }
        body { margin: 0; font: 15px/1.55 system-ui, -apple-system, "Segoe UI", Roboto, sans-serif; background: var(--bg); color: var(--ink); }
        main { max-width: 720px; margin: 40px auto; padding: 0 16px; }
        h1 { font-size: 22px; margin: 0 0 4px; }
        p.lead { color: var(--muted); margin: 0 0 24px; }
        section { background: var(--card); border: 1px solid var(--line); border-radius: 12px; padding: 20px; margin-bottom: 16px; }
        h2 { font-size: 16px; margin: 0 0 12px; }
        ul.checks { list-style: none; padding: 0; margin: 0; }
        ul.checks li { display: flex; gap: 10px; padding: 7px 0; border-top: 1px solid var(--line); }
        ul.checks li:first-child { border-top: 0; }
        .dot { flex: none; width: 20px; font-weight: 700; }
        .ok { color: var(--ok); } .bad { color: var(--bad); } .warn { color: var(--warn); }
        small { color: var(--muted); display: block; word-break: break-all; }
        label { display: block; font-weight: 600; margin: 12px 0 4px; }
        input[type=text], input[type=email], input[type=password] { width: 100%; padding: 10px 12px; border: 1px solid var(--line); border-radius: 8px; font: inherit; }
        .row { display: grid; gap: 12px; grid-template-columns: 1fr 1fr; }
        @media (max-width: 560px) { .row { grid-template-columns: 1fr; } }
        .check { display: flex; gap: 8px; align-items: flex-start; font-weight: 400; margin-top: 16px; }
        button { margin-top: 18px; background: var(--brand); color: #fff; border: 0; border-radius: 8px; padding: 11px 18px; font: inherit; font-weight: 600; cursor: pointer; }
        .alert { border-radius: 8px; padding: 12px 14px; margin-bottom: 16px; }
        .alert.bad { background: #fef3f2; border: 1px solid #fecdca; }
        .alert.ok { background: #ecfdf3; border: 1px solid #abefc6; color: var(--ink); }
        code { background: #f0f1f5; padding: 1px 5px; border-radius: 4px; }
        a { color: var(--brand); }
    </style>
</head>
<body>
<main>
    <h1>Kodmob kurulum</h1>
    <p class="lead">Sunucu denetimi, veritabanı tabloları ve ilk yönetici.</p>

    @if ($error)
        <div class="alert bad">{{ $error }}</div>
    @endif

    @if ($result)
        <div class="alert ok">
            <strong>Kurulum tamam.</strong> Yönetici: <code>{{ $result['admin'] }}</code>.
            @if ($result['import'])
                İçerik yüklendi:
                {{ $result['import']['counts']['projects'] ?? 0 }} proje,
                {{ $result['import']['counts']['services'] ?? 0 }} hizmet,
                {{ $result['import']['counts']['references'] ?? 0 }} referans,
                {{ $result['import']['counts']['posts'] ?? 0 }} yazı;
                {{ ($result['import']['media']['downloaded'] ?? 0) + ($result['import']['media']['reused'] ?? 0) }} görsel
                @if (! empty($result['import']['media']['failed']))
                    ({{ count($result['import']['media']['failed']) }} görsel bulunamadı)
                @endif.
            @endif
            <br>Panel: <a href="{{ $siteUrl }}/panel/">{{ $siteUrl }}/panel/</a> · Site: <a href="{{ $siteUrl }}/">{{ $siteUrl }}/</a>
            <br><br>Güvenlik için şimdi <code>kodmob-core/.env</code> dosyasındaki <code>SETUP_TOKEN</code> değerini silin.
        </div>
    @endif

    <section>
        <h2>Sunucu denetimi</h2>
        <ul class="checks">
            @foreach ($checks as $check)
                <li>
                    <span class="dot {{ $check['ok'] ? 'ok' : ($check['required'] ? 'bad' : 'warn') }}">{{ $check['ok'] ? '✓' : ($check['required'] ? '✕' : '!') }}</span>
                    <span>{{ $check['label'] }}<small>{{ $check['detail'] }}</small></span>
                </li>
            @endforeach
        </ul>
    </section>

    @if ($migrated !== null)
        <section>
            <h2>Veritabanı</h2>
            <p style="margin:0">Tablolar güncel.</p>
            @if (trim($migrated) !== '' && ! str_contains($migrated, 'Nothing to migrate'))
                <small style="white-space:pre-wrap">{{ $migrated }}</small>
            @endif
            @if ($sites)
                <p style="margin:.75rem 0 0">Alt siteler:</p>
                <ul style="margin:.25rem 0 0">
                    @foreach ($sites as $site)
                        <li>{{ $site['key'] }}: {{ $site['ok'] ? 'güncel' : 'HATA — '.$site['output'] }}</li>
                    @endforeach
                </ul>
            @endif
        </section>
    @endif

    @if ($migrated !== null && ! $hasAdmin)
        <section>
            <h2>İlk yönetici</h2>
            <form method="post" action="?token={{ urlencode($token) }}" autocomplete="off">
                <input type="hidden" name="token" value="{{ $token }}">
                <div class="row">
                    <div>
                        <label for="username">Kullanıcı adı</label>
                        <input id="username" name="username" type="text" required minlength="3" maxlength="60" value="{{ $old['username'] ?? '' }}">
                    </div>
                    <div>
                        <label for="display_name">Görünen ad</label>
                        <input id="display_name" name="display_name" type="text" maxlength="120" value="{{ $old['display_name'] ?? '' }}">
                    </div>
                </div>
                <label for="email">E-posta</label>
                <input id="email" name="email" type="email" required value="{{ $old['email'] ?? '' }}">
                <div class="row">
                    <div>
                        <label for="password">Şifre (en az 10 karakter)</label>
                        <input id="password" name="password" type="password" required minlength="10" autocomplete="new-password">
                    </div>
                    <div>
                        <label for="password_confirmation">Şifre (tekrar)</label>
                        <input id="password_confirmation" name="password_confirmation" type="password" required minlength="10" autocomplete="new-password">
                    </div>
                </div>
                @if ($canSeed)
                    <label class="check"><input type="checkbox" name="seed" value="1" checked> Paketle gelen içeriği yükle (projeler, hizmetler, referanslar, blog, ayarlar ve görseller)</label>
                @endif
                <button type="submit">Kurulumu tamamla</button>
            </form>
        </section>
    @elseif ($hasAdmin && ! $result)
        <section>
            <h2>Durum</h2>
            <p style="margin:0">Yönetici hesabı var; kurulum daha önce tamamlanmış. Panel: <a href="{{ $siteUrl }}/panel/">{{ $siteUrl }}/panel/</a></p>
            <small>Yeni sürüm yükledikten sonra bu sayfayı açmak tabloları günceller. İşiniz bitince <code>SETUP_TOKEN</code> değerini silin.</small>
        </section>
    @endif
</main>
</body>
</html>
