/**
 * Mağaza (commerce; docs/done-commerce-raporu.md): `npm run e2e -- --shop` başlangıç profiliyle derler ve Mağaza'yı
 * ortamdan açar (KODMOB_MODULES). Ürün panelden eklenir; ziyaretçi mağazada ürünü bulur, sepete atar, havale
 * ile sipariş verir, sipariş sayfasında banka bilgilerini görür; panelde sipariş "Ödendi" yapılır, stok düşer.
 */
import { test, expect } from '@playwright/test';
import { apiClient, consoleErrors, password, USER } from '../helpers.js';

test.skip(!process.env.E2E_SHOP, 'yalnızca Mağaza kipiyle (npm run e2e -- --shop)');

test('mağaza: ürün, sepet, havale ile sipariş, panelde ödendi', async ({ page, request }) => {
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    expect((await api.get('/me')).json.modules).toContain('commerce');
    expect((await api.put('/settings', { values: { 'commerce.bank_enabled': true, 'commerce.bank_iban': 'TR00 1111 2222 3333 4444 5555 66', 'commerce.bank_name': 'Örnek Bank', 'commerce.shipping_fee': 40 } })).status).toBe(200);
    const created = await api.post('/admin/content/products', {
        status: 'publish', price: '149,90', tax_rate: '20', track_stock: true, stock: 5, sku: 'KUPA-E2E',
        locales: { tr: { status: 'publish' } }, i18n: { tr: { title: 'Seramik Kupa', slug: 'seramik-kupa', summary: 'El yapımı kupa.' } },
    });
    expect(created.status).toBe(201);

    // Ziyaretçi: mağaza → ürün → sepete ekle (2 adet).
    await page.goto('/magaza/');
    const card = page.locator('[data-testid=shop-product]', { hasText: 'Seramik Kupa' });
    await expect(card).toContainText('149,90 ₺');
    await card.click();
    await expect(page).toHaveURL(/\/magaza\/seramik-kupa\/$/);
    await expect(page.locator('[data-testid=product-price]')).toHaveText('149,90 ₺');
    await page.fill('#shop-qty', '2');
    await page.click('[data-testid=add-to-cart]');
    await expect(page.locator('.toast, [role=status]').first()).toBeVisible();

    // Sepet: 2 × 149,90 + kargo 40,00 = 339,80.
    await page.goto('/sepet/');
    await expect(page.locator('[data-testid=cart-item]')).toHaveCount(1);
    await expect(page.locator('[data-testid=shop-total]')).toHaveText('339,80 ₺');
    await page.click('[data-testid=to-checkout]');

    // Ödeme: bilgiler, havale, sözleşme onayı.
    await expect(page).toHaveURL(/\/odeme\/$/);
    await page.fill('#co-name', 'Ayşe Yılmaz');
    await page.fill('#co-email', 'ayse@ornek.test');
    await page.fill('#co-phone', '0532 000 00 00');
    await page.fill('#co-line', 'Atatürk Cad. No 1');
    await page.fill('#co-city', 'İzmir');
    await page.check('[data-payment=bank_transfer]');
    await page.check('[data-testid=terms]');
    await page.click('[data-testid=place-order]');

    // Sipariş sayfası: numara, durum, banka bilgileri.
    await expect(page).toHaveURL(/\/siparis\/\?no=S-\d{6}&anahtar=[0-9a-f]{40}&yeni=1$/);
    const number = (await page.locator('[data-testid=order-number]').textContent()).trim();
    await expect(page.locator('[data-testid=order-status]')).toHaveText('Ödeme bekliyor');
    await expect(page.locator('[data-testid=order-page]')).toContainText('TR00 1111 2222 3333 4444 5555 66');

    // Panel: sipariş listede; açılır, "Ödendi" yapılır. Ürünün stoğu 5 → 3.
    await page.goto('/panel/');
    await page.fill('#login-user', USER);
    await page.fill('#login-pass', password());
    await page.click('button[type=submit]');
    await page.locator('aside nav a[href="/panel/siparisler"]').first().click();
    await page.locator('[data-testid=orders] tr', { hasText: number }).getByRole('button', { name: 'Aç' }).click();
    const drawer = page.locator('[data-testid=order]');
    await expect(drawer.locator('[data-testid=order-status]')).toHaveText('Ödeme bekliyor');
    await drawer.locator('[data-status=paid]').click();
    await expect(drawer.locator('[data-testid=order-status]')).toHaveText('Ödendi');
    const product = (await api.get(`/admin/content/products/${created.json.id}`)).json;
    expect(product.stock ?? product.data?.stock).toBe(3);
    expect(errors()).toEqual([]);
});

test('rezervasyon: tur, tarih ve kişi, anlık fiyat, sepet, kapasite', async ({ page, request }) => {
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    expect((await api.get('/me')).json.modules).toContain('booking');
    const date = new Date(Date.now() + 20 * 86400000).toISOString().slice(0, 10);
    const created = await api.post('/admin/content/tours', {
        status: 'publish', price_adult: '1000', price_child: '500', tax_rate: '20', capacity: 3, dates: [date],
        group_discounts: [{ min: 3, percent: 10 }], locales: { tr: { status: 'publish' } },
        packages: [{ key: 'standart', price: '1000' }, { key: 'vip', price: '1500', price_child: '700' }],
        i18n: { tr: {
            title: 'Mostar Turu', slug: 'mostar-turu', summary: 'Tam gün tur.',
            package_texts: [{ name: 'Standart', subtitle: '' }, { name: 'VIP', subtitle: 'Özel araç' }],
            highlights: ['Mostar Köprüsü'], itinerary: [{ title: 'Sabah', text: 'Yola çıkış' }], faq: [{ q: 'Rehber var mı?', a: 'Evet.' }],
        } },
    });
    expect(created.status).toBe(201);

    // Tur listesi → tur sayfası; sayfa bölümleri profilden.
    await page.goto('/turlar/');
    await page.locator('[data-testid=shop-tour]', { hasText: 'Mostar Turu' }).click();
    await expect(page).toHaveURL(/\/turlar\/mostar-turu\/$/);
    await expect(page.locator('.book-zone')).toContainText('Mostar Köprüsü');
    await expect(page.locator('.book-zone')).toContainText('Rehber var mı?');

    // Sayaçlar: 2 yetişkin + 1 çocuk (Standart) = 2.500; grup (3 kişi) %10 → 2.250; kademe vurgulu.
    const book = page.locator('[data-testid=book]');
    const total = book.locator('[data-testid=book-total]');
    const plus = (label) => book.getByRole('button', { name: `Artır: ${label}` });
    const minus = (label) => book.getByRole('button', { name: `Azalt: ${label}` });
    await plus('Yetişkin').click();
    await plus('Çocuk').click();
    await expect(total).toHaveText('2.250,00 ₺');
    await expect(book).toContainText('Grup indirimi −%10');
    await expect(book.locator('.book-tier.is-active')).toContainText('3+ kişi');

    // Paket: VIP 2 × 1.500 + 700 = 3.700 → %10 → 3.330.
    await book.locator('[data-package="vip"]').click();
    await expect(total).toHaveText('3.330,00 ₺');

    // 4 yetişkin + 1 çocuk kapasiteyi (3) aşar.
    await plus('Yetişkin').click();
    await plus('Yetişkin').click();
    await expect(book.locator('[data-testid=book-error]')).toHaveText('Bu tarihte yeterli yer yok (kalan: 3).');
    await minus('Yetişkin').click();
    await minus('Yetişkin').click();
    await expect(total).toHaveText('3.330,00 ₺');
    await book.locator('[data-testid=book-submit]').click();

    // Sepet: rezervasyon satırı paketin adıyla; kargo yok, adres istenmez.
    await expect(page).toHaveURL(/\/sepet\/$/);
    await expect(page.locator('[data-testid=cart-item]')).toContainText('Mostar Turu · VIP');
    await expect(page.locator('[data-testid=cart-item]')).toContainText('2 yetişkin, 1 çocuk');

    // Telefon: altta fiyat çubuğu, dokununca alttan açılan sayfa.
    await page.setViewportSize({ width: 390, height: 820 });
    await page.goto('/turlar/mostar-turu/');
    await expect(book.locator('.book-card')).toBeHidden();
    await book.locator('[data-testid=book-open]').click();
    await expect(book.locator('.book-card')).toBeVisible();
    await expect(total).toHaveText('1.000,00 ₺');
    await page.keyboard.press('Escape');
    await expect(book.locator('.book-card')).toBeHidden();
    expect(errors()).toEqual([]);
});

test('otel: giriş – çıkış, oda tipi × pansiyon, oda sayısı, anlık fiyat, sepet', async ({ page, request }) => {
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    expect((await api.get('/me')).json.modules).toContain('hotels');
    const created = await api.post('/admin/content/hotels', {
        status: 'publish', price_night: '1000', min_nights: 2, room_persons: 2, tax_rate: '10', check_in: '14:00', check_out: '12:00', stars: '4', location: 'Göreme',
        locales: { tr: { status: 'publish' } },
        rooms: [{ key: 'standart', persons: 2, stock: 3, price_bb: '1500', price_hb: '2000' }, { key: 'aile', persons: 4, stock: 1, price_ai: '4000' }],
        i18n: { tr: {
            title: 'Kaya Otel', slug: 'kaya-otel', summary: 'Kapadokya’da taş oda.',
            room_texts: [{ name: 'Standart oda', subtitle: 'Bahçe manzaralı' }, { name: 'Aile odası', subtitle: '' }],
            amenities: ['Ücretsiz Wi-Fi', 'Açık havuz'],
        } },
    });
    expect(created.status).toBe(201);

    // Otel listesi → otel sayfası: başlayan fiyat (oda kahvaltı), yıldız ve konum, olanaklar.
    await page.goto('/oteller/');
    const card = page.locator('[data-testid=shop-hotel]', { hasText: 'Kaya Otel' });
    await expect(card).toContainText('1.500,00 ₺');
    await card.click();
    await expect(page).toHaveURL(/\/oteller\/kaya-otel\/$/);
    await expect(page.locator('.book-zone')).toContainText('Açık havuz');
    await expect(page.locator('.book-zone')).toContainText('14:00');

    // İlk seçenek seçili: standart, oda kahvaltı; 2 gece × 1.500 = 3.000.
    const book = page.locator('[data-testid=book]');
    const total = book.locator('[data-testid=book-total]');
    const plus = (label) => book.getByRole('button', { name: `Artır: ${label}` });
    await expect(book.locator('[data-room="standart"] [data-board="bb"]')).toHaveAttribute('aria-checked', 'true');
    await expect(total).toHaveText('3.000,00 ₺');
    await expect(book.locator('[data-testid=book-nights]')).toHaveText('2 gece');

    // Yarım pansiyon: 2 × 2.000 = 4.000. Üçüncü kişi (2 yetişkin + 1 çocuk) tek odaya sığmaz; ikinci oda: 2 × 2 × 2.000 = 8.000.
    await book.locator('[data-room="standart"] [data-board="hb"]').click();
    await expect(total).toHaveText('4.000,00 ₺');
    await plus('Yetişkin').click();
    await plus('Çocuk').click();
    await expect(book.locator('[data-testid=book-error]')).toContainText('en çok 2 kişi');
    await plus('Oda').click();
    await expect(total).toHaveText('8.000,00 ₺');
    await book.locator('[data-testid=book-submit]').click();

    // Sepet: oda tipi ve pansiyon, tarih aralığı, gece ve oda sayısı.
    await expect(page).toHaveURL(/\/sepet\/$/);
    const item = page.locator('[data-testid=cart-item]');
    await expect(item).toContainText('Kaya Otel · Standart oda · Yarım pansiyon');
    await expect(item).toContainText('2 gece · 2 oda · 2 yetişkin, 1 çocuk');
    expect(errors()).toEqual([]);
});

test('panel: satırdaki para sütunu TL olarak görünür, kaydetmek fiyatı değiştirmez', async ({ page, request }) => {
    const api = await apiClient(request);
    const created = await api.post('/admin/content/hotels', {
        status: 'publish', price_night: '1000', tax_rate: '10', locales: { tr: { status: 'publish' } },
        rooms: [{ key: 'standart', persons: 2, stock: 3, price_bb: '1500' }],
        i18n: { tr: { title: 'Fiyat Otel', slug: 'fiyat-otel', room_texts: [{ name: 'Standart', subtitle: '' }] } },
    });
    expect(created.status).toBe(201);
    await page.goto('/panel/');
    await page.fill('#login-user', USER);
    await page.fill('#login-pass', password());
    await page.click('button[type=submit]');
    await expect(page.locator('aside nav a[href="/panel/oteller"]').first()).toBeVisible({ timeout: 20000 });
    await page.goto(`/panel/oteller/${created.json.id}`);
    const price = page.getByRole('textbox', { name: 'Oda kahvaltı' }).first();
    await expect(price).toHaveValue('1500,00');
    await price.fill('1750,50');
    await page.getByRole('button', { name: 'Kaydet' }).click();
    await expect(page.locator('.pn-dirty')).toBeHidden();
    expect((await api.get(`/admin/content/hotels/${created.json.id}`)).json.rooms[0].price_bb).toBe(175050);
    // Kayıttan sonra tekrar kaydetmek (değişiklik olmadan) fiyatı büyütmez.
    await page.reload();
    await expect(price).toHaveValue('1750,50');
});

test('tur sayfasının bölümleri bloklardan: sıra, başlık ve görünürlük Sayfa düzenleyiciden', async ({ page, request }) => {
    const errors = consoleErrors(page);
    const api = await apiClient(request);
    const created = await api.post('/admin/content/tours', {
        status: 'publish', price_adult: '500', tax_rate: '20', locales: { tr: { status: 'publish' } },
        i18n: { tr: { title: 'Blok Turu', slug: 'blok-turu', highlights: ['Gün batımı'], itinerary: [{ title: 'Sabah buluşması', text: 'Otelden alınış' }], faq: [{ q: 'İptal var mı?', a: 'Evet.' }] } },
    });
    expect(created.status).toBe(201);

    // İlk bloklar şablonun verdiği sırayla; "Kayıt sayfası" blokları yalnızca kayıt sayfası şablonunda eklenir.
    const layouts = (await api.get('/admin/layouts')).json;
    const defaults = layouts.zones['tour.sections'].defaults;
    expect(defaults.map((b) => b.type)).toEqual(['entry-facts', 'entry-highlights', 'entry-itinerary', 'entry-includes', 'entry-faq']);
    expect(layouts.blocks['entry-faq'].scope).toBe('entry');
    const zone = page.locator('.book-zone');
    await page.goto('/turlar/blok-turu/');
    await expect(zone).toContainText('Gün batımı');
    await expect(zone).toContainText('Sabah buluşması');

    // SSS başa ve başlığı değişik; program çıkarıldı.
    await api.put('/admin/layouts/tour.sections', { blocks: [{ type: 'entry-faq', i18n: { tr: { title: 'Merak edilenler' } } }, { type: 'entry-highlights' }] });
    await api.post('/admin/layouts/publish', { zones: ['tour.sections'] });
    await page.goto('/turlar/blok-turu/');
    expect(await zone.locator('[data-km-block]').evaluateAll((els) => els.map((e) => e.dataset.kmBlock))).toEqual(['entry-faq', 'entry-highlights']);
    await expect(zone).toContainText('Merak edilenler');
    await expect(zone).toContainText('İptal var mı?');
    await expect(zone).not.toContainText('Sabah buluşması');

    // Toparla: ilk bloklara dön.
    await api.put('/admin/layouts/tour.sections', { blocks: defaults });
    await api.post('/admin/layouts/publish', { zones: ['tour.sections'] });
    expect(errors()).toEqual([]);
});
