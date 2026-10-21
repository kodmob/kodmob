/**
 * Yönetim paneli (/panel/). Site sayfalarından ayrı bir giriştir: içerik
 * verisi yüklemez (startPage kullanmaz), kendi Alpine bileşenleriyle ve kendi
 * stil paketiyle (panel tasarım dili, styles/panel/) çalışır.
 *
 * Burada yalnızca kabuk var (giriş, menü, arama, bildirimler, medya penceresi); görünümler ilk
 * açıldıklarında indirilir (src/panel/views.js).
 */
import { applyStoredTheme } from '@/ui/theme/theme.js';
import Alpine from 'alpinejs';
import collapse from '@alpinejs/collapse';
import '@/styles/panel/index.css';

import { createToastStore } from '@/stores/toast.js';
import { createSessionStore } from '@/stores/session.js';
import { registerUi } from '@/ui/index.js';
// Projenin panel uzantısı (profilin panel.js'i: içerik türleri, özel sayfalar) adres tablosuna ilk iş verilir.
import '@/panel/project.js';
import { createPanelStore, kmPanel } from '@/panel/app.js';
import { href } from '@/panel/routes.js';
import { createPulseStore } from '@/panel/pulse.js';
import { createMediaStore } from '@/panel/media.js';
import { kmNotifications } from '@/panel/notifications.js';
import { kmLogin } from '@/panel/login.js';
import { kmSearch } from '@/panel/search.js';

applyStoredTheme();

Alpine.plugin(collapse);
// Panel adresleri şablonlarda: :href="$href('editor', { type: 'projects', id: 12 })" (routes.js).
Alpine.magic('href', () => href);
Alpine.store('toast', createToastStore());
Alpine.store('session', createSessionStore());
Alpine.store('panel', createPanelStore());
Alpine.store('pulse', createPulseStore());
Alpine.store('media', createMediaStore());
registerUi(Alpine);
Object.entries({ kmPanel, kmNotifications, kmLogin, kmSearch }).forEach(([name, factory]) => Alpine.data(name, factory));

window.Alpine = Alpine;
Alpine.start();
