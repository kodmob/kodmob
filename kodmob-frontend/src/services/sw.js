/**
 * Service worker kaydı (public/sw.js; site ve panel aynı, kapsam /) ve anlık bildirim aboneliği.
 * Yalnızca güvenli bağlamda (https ya da yerel 127.0.0.1 / localhost) çalışır; desteklemeyen tarayıcıda sessizce
 * atlanır. Kayıt sayfa açıldıktan sonra yapılır: ilk çizimi bekletmez.
 */
const SUPPORTED = typeof navigator !== 'undefined' && 'serviceWorker' in navigator && typeof window !== 'undefined' && window.isSecureContext;

let registration = null;

export function registerServiceWorker() {
    if (!SUPPORTED) return Promise.resolve(null);
    registration ??= new Promise((resolve) => {
        const start = () => navigator.serviceWorker.register('/sw.js', { scope: '/' }).then(resolve, () => resolve(null));
        if (document.readyState === 'complete') start();
        else window.addEventListener('load', start, { once: true });
    });
    return registration;
}

/** Bu tarayıcıda anlık bildirim mümkün mü: 'unsupported' | 'denied' | 'default' | 'granted'. */
export function pushPermission() {
    if (!SUPPORTED || !('PushManager' in window) || !('Notification' in window)) return 'unsupported';
    return Notification.permission;
}

/** Bu cihazın şu anki aboneliği (yoksa null). */
export async function currentSubscription() {
    if (pushPermission() === 'unsupported') return null;
    const reg = await registerServiceWorker();
    return reg ? reg.pushManager.getSubscription() : null;
}

const toKey = (base64url) => {
    const raw = atob(base64url.replace(/-/g, '+').replace(/_/g, '/') + '='.repeat((4 - (base64url.length % 4)) % 4));
    return Uint8Array.from(raw, (c) => c.charCodeAt(0));
};

/**
 * İzin ister ve abone olur; sunucuya gidecek düz gövdeyi döner ({ endpoint, p256dh, auth }).
 * İzin verilmezse hata (`code: 'denied'`).
 */
export async function subscribePush(publicKey) {
    const permission = await Notification.requestPermission();
    if (permission !== 'granted') throw Object.assign(new Error('Bildirim izni verilmedi. Tarayıcının site ayarlarından açabilirsiniz.'), { code: 'denied' });
    const reg = await registerServiceWorker();
    if (!reg) throw new Error('Bu tarayıcı anlık bildirimi desteklemiyor.');
    await navigator.serviceWorker.ready;
    const subscription = (await reg.pushManager.getSubscription()) || (await reg.pushManager.subscribe({ userVisibleOnly: true, applicationServerKey: toKey(publicKey) }));
    const json = subscription.toJSON();
    return { endpoint: json.endpoint, p256dh: json.keys?.p256dh || '', auth: json.keys?.auth || '' };
}

/** Bu cihazın aboneliğini bırakır; bırakılan adresi döner (sunucuya bildirmek için). */
export async function unsubscribePush() {
    const subscription = await currentSubscription();
    if (!subscription) return null;
    const endpoint = subscription.endpoint;
    await subscription.unsubscribe().catch(() => {});
    return endpoint;
}
