/**
 * Panel uçları (kodmob-core routes/api.php). Hepsi oturum ister ve yetkiye bağlıdır
 * (`cap:`); token http.js'te eklenir ve gerekirse yenilenir.
 * `type`: projects | services | references | posts.
 */
import { http } from '../services/http.js';

export const api = {
    overview: () => http.get('/admin/overview'),
    /** Genel bakış kartları ve kişinin düzeni (Core\Dashboard\Widgets). */
    dashboard: () => http.get('/admin/dashboard'),
    dashboardSave: (body) => http.put('/me/dashboard', body),
    dashboardReset: () => http.delete('/me/dashboard'),
    /** Çok dilli panel (Sistem Ayarları › Panel dili; Profilim). */
    panelLanguages: () => http.get('/system/panel-languages'),
    panelLanguageExport: (code, name) => http.download('/system/panel-languages/export', { code, name }),
    panelLanguageImport: (body) => http.post('/system/panel-languages/import', body, { timeout: 60000 }),
    panelLanguageDelete: (code) => http.delete(`/system/panel-languages/${code}`),
    panelLanguageChoose: (code) => http.put('/me/panel-language', { code }),
    /** Site üyeleri (Gelenler › Üyeler). */
    members: (params) => http.get('/admin/members', params),
    memberUpdate: (id, body) => http.put(`/admin/members/${id}`, body),
    memberDelete: (id) => http.delete(`/admin/members/${id}`),

    schema: (type) => http.get(`/admin/schema/${type}`),
    list: (type) => http.get(`/admin/content/${type}`),
    blank: (type) => http.get(`/admin/content/${type}/new`),
    get: (type, id) => http.get(`/admin/content/${type}/${id}`),
    create: (type, body) => http.post(`/admin/content/${type}`, body),
    update: (type, id, body) => http.put(`/admin/content/${type}/${id}`, body),
    remove: (type, id) => http.delete(`/admin/content/${type}/${id}`),
    order: (type, ids) => http.post(`/admin/content/${type}/order`, { ids }),
    options: (type) => http.get(`/admin/options/${type}`),

    // Çeviriler (done-coklu-dil-raporu.md Faz 4). Jarvis çevirisi uzun sürebilir.
    translate: (type, body) => http.post(`/admin/translate/${type}`, body, { timeout: 180000 }),
    createPostTranslation: (id, body) => http.post(`/admin/content/posts/${id}/translations`, body, { timeout: 180000 }),
    linkPost: (id, postId) => http.post(`/admin/content/posts/${id}/link`, { post_id: postId }),
    unlinkPost: (id) => http.post(`/admin/content/posts/${id}/unlink`),

    /** Medya kitaplığı: { items, total, pages }. Her öğe { id, src, thumb, alts: { tr, en, de }, … }. */
    media: (query) => http.get('/admin/media', query),

    /** Görsel yükler (alt metin isteğe bağlı; seçmeden önce istenir). */
    upload(file, alts = {}) {
        const body = new FormData();
        body.append('file', file);
        Object.entries(alts).forEach(([code, text]) => text && body.append(`alts[${code}]`, text));
        return http.post('/admin/media', body, { timeout: 90000 });
    },

    uploadUrl: (url, alts = {}) => http.post('/admin/media/url', { url, alts }, { timeout: 60000 }),
    saveAlts: (id, alts) => http.put(`/admin/media/${id}`, { alts }),

    /** Sayfa metinleri: { texts: { anahtar: { on, tr, en, de } }, values: { dil: { anahtar: metin } } } */
    texts: () => http.get('/admin/texts'),
    saveTexts: (values) => http.put('/admin/texts', { values }),
    /** Jarvis ile çeviri (kaydetmez): { from, to, texts: { anahtar: metin } } → { texts } */
    translateTexts: (body) => http.post('/admin/texts/translate', body, { timeout: 180000 }),
    /**
     * Sayfa düzenleyici (kodmob-core Site\Http\LayoutsController): kitaplık, sayfalar, bölgeler; taslak her
     * değişiklikte kaydedilir, "Yayınla" bütün taslakları yayına alır.
     */
    layouts: () => http.get('/admin/layouts'),
    saveLayout: (zone, blocks) => http.put(`/admin/layouts/${zone}`, { blocks }),
    discardLayout: (zone) => http.delete(`/admin/layouts/${zone}/draft`),
    /** `pages`: taslaktaki blok sayfaları da yayına alınır (kimlikler). */
    publishLayouts: (zones = null, pages = []) => http.post('/admin/layouts/publish', { ...(zones ? { zones } : {}), ...(pages.length ? { pages } : {}) }),
    /** Önizleme HTML'i: { block } → { html } ya da { blocks } → { items: [{ id, html }] } */
    renderBlocks: (body) => http.post('/admin/layouts/render', body),
    layoutRevisions: (zone) => http.get(`/admin/layouts/${zone}/revisions`),
    restoreLayout: (zone, id) => http.post(`/admin/layouts/${zone}/revisions/${id}`),
    previewToken: () => http.post('/admin/layouts/preview'),
    // Jarvis: blok metni yaz, çevir, sayfaya bölüm öner (kaydetmez).
    assistBlock: (body) => http.post('/admin/layouts/assist', body, { timeout: 120000 }),
    translateBlock: (body) => http.post('/admin/layouts/translate', body, { timeout: 180000 }),
    suggestBlocks: (body) => http.post('/admin/layouts/suggest', body, { timeout: 180000 }),
    /** Keşfet › Bloklar: { items, categories }; kitaplığa ekle / çıkar. */
    blocks: () => http.get('/admin/blocks'),
    toggleBlock: (type, added) => http.put(`/admin/blocks/${type}`, { added }),

    estimate: () => http.get('/admin/estimate'),
    saveEstimate: (value) => http.put('/admin/estimate', { value }),

    settings: () => http.get('/settings'),

    // E-posta ve arka plan işleri (Ayarlar > E-posta, Ayarlar > Arka plan)
    mailSettings: () => http.get('/system/mail'),
    saveMailSettings: (body) => http.put('/system/mail', body),
    testMail: (to) => http.post('/system/mail/test', { to }, { timeout: 60000 }),
    // Yapay zekâ sağlayıcıları (Ayarlar > Yapay zekâ; Claude, OpenAI, Gemini)
    aiSettings: () => http.get('/system/ai'),
    saveAiSettings: (body) => http.put('/system/ai', body),
    testAi: (provider) => http.post('/system/ai/test', { provider }, { timeout: 120000 }),
    aiModels: (provider) => http.post('/system/ai/models', { provider }, { timeout: 45000 }),
    // SMS (Ayarlar > SMS; NetGSM)
    smsSettings: () => http.get('/system/sms'),
    saveSmsSettings: (body) => http.put('/system/sms', body),
    smsSenders: () => http.post('/system/sms/senders', {}, { timeout: 45000 }),
    testSms: (to, message) => http.post('/system/sms/test', { to, message }, { timeout: 45000 }),
    jobs: () => http.get('/system/jobs'),
    retryJob: (uuid) => http.post(`/system/jobs/${encodeURIComponent(uuid)}/retry`),
    forgetJob: (uuid) => http.delete(`/system/jobs/${encodeURIComponent(uuid)}`),
    // Sistem Sağlığı: kartlar (Core\Health), hata kodları, Bildir
    health: () => http.get('/system/health', undefined, { timeout: 60000 }),
    healthErrors: () => http.get('/system/health/errors').then((r) => r.items || []),
    clearHealthErrors: () => http.delete('/system/health/errors'),
    healthReport: () => http.post('/system/health/report', {}, { timeout: 60000 }),
    saveSettings: (values) => http.put('/settings', { values }),

    // Yedek: `personal` kişisel kayıtlar da (siparişler, talepler, aboneler; Module::backup)
    backup: (personal = false) => http.get(`/admin/backup${personal ? '?personal=1' : ''}`, undefined, { timeout: 120000 }),
    restore: (backup, personal = false) => http.post('/admin/backup', { ...backup, personal }, { timeout: 600000 }),
    restoreFile: (form) => http.post('/admin/backup', form, { timeout: 600000 }),
    snapshots: () => http.get('/admin/backups'),
    // Otomatik yedek (zip: içerik + görseller; isteğe bağlı S3 uyumlu depo)
    autoBackup: () => http.get('/admin/backups/auto'),
    saveAutoBackup: (body) => http.put('/admin/backups/auto', body),
    runAutoBackup: () => http.post('/admin/backups/auto/run', {}, { timeout: 600000 }),
    testAutoBackupRemote: () => http.post('/admin/backups/auto/test', {}, { timeout: 60000 }),
    restoreAutoBackup: (name, body = {}) => http.post(`/admin/backups/auto/${encodeURIComponent(name)}/restore`, body, { timeout: 600000 }),
    snapshot: (name) => http.get(`/admin/backups/${encodeURIComponent(name)}`, undefined, { timeout: 120000 }),

    // Çoklu Dil (Modules/I18n)
    languages: () => http.get('/languages'),
    createLanguage: (body) => http.post('/languages', body),
    updateLanguage: (code, body) => http.put(`/languages/${code}`, body),
    deleteLanguage: (code) => http.delete(`/languages/${code}`),
    orderLanguages: (codes) => http.post('/languages/order', { codes }),
    languagesOverview: () => http.get('/languages/overview'),
    languagesHealth: () => http.get('/languages/health'),
    languagesUrls: () => http.get('/languages/urls'),
    languagesVisitor: () => http.get('/languages/visitor'),
    saveLanguagesVisitor: (popular) => http.put('/languages/visitor', { popular }),

    // Bildirim zili (Modules/Notifications)
    notifications: () => http.get('/notifications'),
    notificationsRead: (body) => http.post('/notifications/read', body),
    /** Bildirimler sayfası: { page, per_page, status, kind } → { items, total, pages, unread, kinds }. */
    notificationsPage: (params) => http.get('/notifications', params),
    notificationsUnread: (body) => http.post('/notifications/unread', body),
    notificationsDelete: (body) => http.post('/notifications/delete', body),
    /** Anlık bildirim: bu cihazın durumu, aç / kapat, deneme. */
    push: (endpoint) => http.get('/push', endpoint ? { endpoint } : {}),
    pushSubscribe: (body) => http.post('/push/subscribe', body),
    pushUnsubscribe: (body) => http.post('/push/unsubscribe', body),
    pushTest: () => http.post('/push/test', {}),

    // Jarvis (Modules/Jarvis)
    jarvis: () => http.get('/jarvis'),
    jarvisJobs: (query) => http.get('/jarvis/jobs', query),
    jarvisJob: (id) => http.get(`/jarvis/jobs/${id}`),
    jarvisJobControl: (id, action) => http.post(`/jarvis/jobs/${id}/${action}`),
    jarvisJobDelete: (id) => http.delete(`/jarvis/jobs/${id}`),
    jarvisTick: (body = {}) => http.post('/jarvis/tick', body, { timeout: 45000 }),
    domainOptions: () => http.get('/jarvis/domains/options'),
    domainPreview: (spec) => http.post('/jarvis/domains/preview', spec),
    domainScan: (spec) => http.post('/jarvis/domains/scan', spec, { timeout: 45000 }),
    domainCheck: (body) => http.post('/jarvis/domains/check', body, { timeout: 90000 }),
    domains: (query) => http.get('/jarvis/domains', query),
    domainExport: (query) => http.download('/jarvis/domains/export', query, { timeout: 120000 }),
    conversations: () => http.get('/jarvis/conversations'),
    conversationCreate: (body = {}) => http.post('/jarvis/conversations', body),
    conversation: (id) => http.get(`/jarvis/conversations/${id}`),
    conversationRename: (id, title) => http.patch(`/jarvis/conversations/${id}`, { title }),
    conversationDelete: (id) => http.delete(`/jarvis/conversations/${id}`),
    conversationSend: (id, text) => http.post(`/jarvis/conversations/${id}/messages`, { text }, { timeout: 180000 }),
    conversationContinue: (id) => http.post(`/jarvis/conversations/${id}/continue`, {}, { timeout: 180000 }),
    /** Operatör: Jarvis'in yazma önerisini onayla / reddet; yanıt sohbetin yeni hali. */
    jarvisDecide: (id, approve) => http.post(`/jarvis/actions/${id}/${approve ? 'approve' : 'reject'}`, {}, { timeout: 180000 }),

    // Oturum, profil (Modules/Auth)
    me: () => http.get('/me'),
    saveMe: (body) => http.put('/me', body),
    changePassword: (body) => http.put('/me/password', body),
    mySessions: () => http.get('/me/sessions'),
    endMySession: (id) => http.delete(`/me/sessions/${id}`),
    endMyOtherSessions: () => http.post('/me/sessions/end-others'),

    // İşlem Geçmişi (Modules/History)
    history: (query) => http.get('/history', query),
    historyStats: (query) => http.get('/history/stats', query),
    historyOptions: () => http.get('/history/options'),
    historyItem: (id) => http.get(`/history/${id}`),

    // Kullanıcılar ve roller (Modules/Users)
    users: (query) => http.get('/users', query),
    userOptions: () => http.get('/users/options'),
    user: (id) => http.get(`/users/${id}`),
    createUser: (body) => http.post('/users', body),
    updateUser: (id, body) => http.put(`/users/${id}`, body),
    deactivateUser: (id) => http.post(`/users/${id}/deactivate`),
    activateUser: (id) => http.post(`/users/${id}/activate`),
    endUserSessions: (id) => http.post(`/users/${id}/sessions/end`),
    resetUserTwoFactor: (id) => http.post(`/users/${id}/two-factor/reset`),
    smsUsers: (ids, message) => http.post('/users/sms', { ids, message }, { timeout: 45000 }),
    mailUsers: (ids, subject, message) => http.post('/users/mail', { ids, subject, message }, { timeout: 60000 }),

    // Panel araması (üst çubuk): kayıtlar; sayfalar ve ayarlar panelde aranır (search-index.js)
    search: (q) => http.get('/search', { q }),

    // Kişiye özel tercihler (Diğer İşlemler > Hızlı İşlemler)
    quickActions: () => http.get('/me/quick-actions'),
    saveQuickActions: (keys) => http.put('/me/quick-actions', { keys }),
    resetQuickActions: () => http.delete('/me/quick-actions'),

    // İki adımlı doğrulama (Profilim)
    twoFactorSetup: (password) => http.post('/me/two-factor/setup', { password }),
    twoFactorConfirm: (code) => http.post('/me/two-factor/confirm', { code }),
    twoFactorDisable: (password) => http.post('/me/two-factor/disable', { password }),
    twoFactorRecovery: (password) => http.post('/me/two-factor/recovery', { password }),
    // Siteler (çok site; yalnızca ana sitede). Kurulum ve güncelleme tablolar kurulurken sürebilir.
    // Araç Pazarı (Core\Modules): projede kurulu araçlar, aç / kapat.
    tools: () => http.get('/system/tools'),
    toolsCatalogRefresh: () => http.post('/system/tools/catalog/refresh', {}, { timeout: 30000 }),
    toggleTool: (key, enabled) => http.put(`/system/tools/${key}`, { enabled }),
    /** Çatının aracını derlemesiz ekler / çıkarır: { items, with } (`with`: birlikte eklenen takıldığı araçlar). */
    installTool: (key, installed) => http.put(`/system/tools/${key}/install`, { installed }),
    /** API'nin OpenAPI belgesi (yalnızca km_manage_settings). */
    openapi: () => http.get('/system/openapi'),
    sites: () => http.get('/system/sites'),
    createSite: (body) => http.post('/system/sites', body, { timeout: 180000 }),
    // Site Sihirbazı: yapay zekâ önerisi (sayfa metinleri tek çağrıda; çok dilde biraz sürer)
    planSite: (body) => http.post('/system/sites/plan', body, { timeout: 240000 }),
    updateSite: (key, body) => http.put(`/system/sites/${key}`, body),
    handoffSite: (key) => http.post(`/system/sites/${key}/handoff`),
    migrateSites: (key = 'all') => http.post(`/system/sites/${key}/migrate`, {}, { timeout: 180000 }),
    removeSite: (key) => http.delete(`/system/sites/${key}`),
    roles: () => http.get('/roles'),
    createRole: (body) => http.post('/roles', body),
    renameRole: (slug, body) => http.put(`/roles/${slug}`, body),
    saveRoleCaps: (slug, caps) => http.put(`/roles/${slug}/caps`, { caps }),
    saveRoleMenu: (slug, groups) => http.put(`/roles/${slug}/menu`, { groups }),
    deleteRole: (slug) => http.delete(`/roles/${slug}`),

    // Güvenlik (Modules/Security)
    security: () => http.get('/security'),
    securityEvents: (query) => http.get('/security/events', query),
    securitySessions: () => http.get('/security/sessions'),
    endSession: (id) => http.delete(`/security/sessions/${id}`),
    endAllSessions: () => http.post('/security/sessions/end-all'),
    rotateKey: () => http.post('/security/key/rotate'),
    ipBlocks: () => http.get('/security/blocks'),
    blockIp: (body) => http.post('/security/blocks', body),
    unblockIp: (id) => http.delete(`/security/blocks/${id}`),
    lockouts: () => http.get('/security/lockouts'),
    unlock: (body) => http.post('/security/lockouts/unlock', body),
    settingsGroup: (group) => http.get('/settings', { group }),
    settingsDefaults: (body) => http.post('/settings/defaults', body),

    // SEO (Modules/Seo)
    seo: () => http.get('/seo'),
    seoContent: () => http.get('/seo/content'),
    seoAi: () => http.get('/seo/ai', undefined, { timeout: 60000 }),
    seoAnalyze: (body) => http.post('/seo/analyze', body),
    seoMeta: (type, id) => http.get(`/seo/meta/${type}/${id}`),
    saveSeoMeta: (type, id, body) => http.put(`/seo/meta/${type}/${id}`, body),
    seoPages: () => http.get('/seo/pages'),
    saveSeoPage: (body) => http.put('/seo/pages', body),
    redirects: (query) => http.get('/seo/redirects', query),
    createRedirect: (body) => http.post('/seo/redirects', body),
    updateRedirect: (id, body) => http.put(`/seo/redirects/${id}`, body),
    deleteRedirect: (id) => http.delete(`/seo/redirects/${id}`),
    testRedirect: (url) => http.post('/seo/redirects/test', { url }),
    exportRedirects: () => http.download('/seo/redirects/export'),
    importRedirects: (csv) => http.post('/seo/redirects/import', { csv }),
    notFound: (query) => http.get('/seo/404', query),
    notFoundStatus: (ids, status) => http.post('/seo/404/status', { ids, status }),
    notFoundDelete: (ids) => http.post('/seo/404/delete', { ids }),
    /** İçerikteki kırık / yönlenen iç bağlantılar: { checked_at, items } */
    seoLinks: () => http.get('/seo/links', undefined, { timeout: 60000 }),
    checkSeoLinks: () => http.post('/seo/links/check', {}, { timeout: 120000 }),
    /** Yönlenen bağlantıları içerikte yeni adresleriyle değiştirir: { fixed, checked_at, items } */
    fixSeoLinks: () => http.post('/seo/links/fix', {}, { timeout: 120000 }),
    robots: () => http.get('/seo/robots'),
    saveRobots: (extra) => http.put('/seo/robots', { extra }),
    indexNow: () => http.get('/seo/indexnow'),
    submitIndexNow: (urls = []) => http.post('/seo/indexnow/submit', { urls }, { timeout: 30000 }),

    briefs: (query) => http.get('/briefs', query),

    // Mağaza (commerce): siparişler ve kuponlar
    orders: (query) => http.get('/admin/commerce/orders', query),
    shopOrder: (id) => http.get(`/admin/commerce/orders/${id}`),
    orderStatus: (id, status) => http.post(`/admin/commerce/orders/${id}/status`, { status }),
    coupons: () => http.get('/admin/commerce/coupons'),
    saveCoupon: (id, body) => (id ? http.put(`/admin/commerce/coupons/${id}`, body) : http.post('/admin/commerce/coupons', body)),
    deleteCoupon: (id) => http.delete(`/admin/commerce/coupons/${id}`),
    bookings: () => http.get('/admin/commerce/bookings'),
    // Yasal belgeler (kodmob-core Site\Legal)
    legalDocs: () => http.get('/admin/legal'),
    legalDoc: (name, locale) => http.get(`/admin/legal/${name}/${locale}`),
    saveLegal: (name, locale, body) => http.put(`/admin/legal/${name}/${locale}`, { body }),
    resetLegal: (name, locale) => http.delete(`/admin/legal/${name}/${locale}`),
    // Bülten (isteğe bağlı araç newsletter)
    newsletterSubscribers: () => http.get('/admin/newsletter/subscribers'),
    addSubscriber: (body) => http.post('/admin/newsletter/subscribers', body),
    deleteSubscriber: (id) => http.delete(`/admin/newsletter/subscribers/${id}`),
    newsletterCampaigns: () => http.get('/admin/newsletter/campaigns'),
    saveCampaign: (id, body) => (id ? http.put(`/admin/newsletter/campaigns/${id}`, body) : http.post('/admin/newsletter/campaigns', body)),
    deleteCampaign: (id) => http.delete(`/admin/newsletter/campaigns/${id}`),
    /** `action`: test (kendi adresime) | send (başlat / sürdür) | pause | continue (cron yokken bir parça). */
    campaignAction: (id, action) => http.post(`/admin/newsletter/campaigns/${id}/${action}`),
    brief: (id) => http.get(`/admin/briefs/${id}`),
    briefStatus: (id, status) => http.post(`/admin/briefs/${id}/status`, { status }),
    briefDelete: (id) => http.delete(`/admin/briefs/${id}`),
};
