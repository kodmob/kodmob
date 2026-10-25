/**
 * Node testleri için (`npm test`: node --import): `@profile/…` test profiline (tests/fixtures/profile) çözülür
 * (kodmob-frontend/profile.js; tarayıcıda Vite takma adı) ve panelin içerik türleri adres tablosuna verilir. Çatının testleri
 * sitenin etkin profiline bağlı değildir; profilin kendi testleri `src/profiles/<ad>/tests/`'te.
 */
import path from 'node:path';
import { fileURLToPath } from 'node:url';
import { register } from 'node:module';

// Çözümleme kancası ayrı iş parçacığında açılır ve ortamın o anki kopyasını alır: önce ayarlanır.
process.env.KM_PROFILE_DIR ||= path.join(path.dirname(fileURLToPath(import.meta.url)), 'fixtures', 'profile');
register('./profile-resolve.mjs', import.meta.url);
await import('../src/panel/types.js');
