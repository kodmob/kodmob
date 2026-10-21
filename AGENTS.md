# AGENTS.md

Bu deponun kuralları, komutları ve mimarisi [CLAUDE.md](CLAUDE.md)'dedir; her yapay zekâ aracı önce onu okur.

Özet: Laravel 12 (`kodmob-api/`) + Vite/Alpine/Tailwind (`tv/`), tek kurulumda çok site, SQLite/MySQL/PostgreSQL.
Testler: `cd kodmob-api && php artisan test`, `cd tv && npm test`, `npm run test:db`, `npm run e2e`.
Büyük değişiklikten önce `docs/sablonlar/etki-analizi.md`. Geliştirici belgeleri `docs/gelistirici/`, dizin `docs/llms.txt`.
