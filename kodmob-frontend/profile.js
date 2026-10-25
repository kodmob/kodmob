/**
 * Etkin site profili: projenin sitesi kodmob-frontend/src/profiles/<ad>/'dadır (site.config.js, routes.config.js, locales.js,
 * i18n/, pages/, shell/). Çatı kodmob-frontend/src'nin geri kalanıdır: panel, bileşenler, stiller, düzen, çatı sözlüğü.
 * KM_PROFILE ortamı üstündedir: başlangıç profilini denemek için `KM_PROFILE=starter npm run build`.
 * KM_PROFILE_DIR profilin klasörünü doğrudan verir (birim testleri: tests/fixtures/profile).
 *
 * Yalnızca Node tarafı okur (derleme, betikler, testler; kodmob-core modül üreticisi de buradaki adı arar).
 * Tarayıcı kodu profili `@profile/…` takma adıyla alır (vite.config.js).
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

export const PROFILE = process.env.KM_PROFILE || 'starter';

export const PROFILE_DIR = process.env.KM_PROFILE_DIR
    ? path.resolve(process.env.KM_PROFILE_DIR)
    : path.resolve(path.dirname(fileURLToPath(import.meta.url)), 'src', 'profiles', PROFILE);

if (!fs.existsSync(PROFILE_DIR)) throw new Error(`Site profili yok: ${PROFILE_DIR} (KM_PROFILE=${PROFILE})`);
