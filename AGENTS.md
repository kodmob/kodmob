# AGENTS.md

Bu deponun kuralları, komutları ve mimarisi [CLAUDE.md](CLAUDE.md)'dedir; her yapay zekâ aracı önce onu okur.

Özet: Laravel 12 (`kodmob-core/`) + Vite/Alpine/Tailwind (`kodmob-frontend/`), tek kurulumda çok site, SQLite/MySQL/PostgreSQL.
Testler: `cd kodmob-core && php artisan test`, `cd kodmob-frontend && npm test`, `npm run test:db`, `npm run e2e`.
Büyük değişiklikten önce `docs/sablonlar/etki-analizi.md`. Geliştirici belgeleri `docs/gelistirici/`, dizin `docs/llms.txt`.
