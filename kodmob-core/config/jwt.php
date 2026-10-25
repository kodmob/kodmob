<?php

/*
 * Yönetici oturumu (jwt-kodmob eklentisinin yerine).
 *
 * - Erişim jetonu: HS256 imzalı JWT, kısa ömürlü, sunucuda saklanmaz.
 * - Yenileme jetonu: rastgele 64 karakter, veritabanında yalnızca SHA-256 özeti
 *   durur ve TEK KULLANIMLIKTIR (her yenilemede yenisi verilir).
 * - Çıkışta erişim jetonunun `jti` değeri süresi dolana kadar iptal listesine girer.
 */
return [
    // Boşsa APP_KEY'den türetilir. Ayrı tutmak, APP_KEY değişince oturumların düşmemesini sağlar.
    'secret' => env('JWT_SECRET'),

    'access_ttl_minutes' => (int) env('JWT_ACCESS_TTL_MINUTES', 60),
    'refresh_ttl_days' => (int) env('JWT_REFRESH_TTL_DAYS', 30),

    // Hatalı giriş kilidi: kullanıcı adı + IP başına.
    'login_max_attempts' => (int) env('LOGIN_MAX_ATTEMPTS', 5),
    'login_lockout_minutes' => (int) env('LOGIN_LOCKOUT_MINUTES', 15),
];
