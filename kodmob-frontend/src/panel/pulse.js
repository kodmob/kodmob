/**
 * Değişiklik nabzı: bir şey değişince açık panel
 * sekmelerinin haberdar olması. `$store.pulse.topics` = { konu: sürüm }.
 *
 * Sunucu, her konunun son değişme anını statik bir dosyada tutar (kodmob-core
 * Modules/Live; adres `GET /live` ile öğrenilir):
 *
 *     { "v": 1, "t": { "brief": 1759751234123, "notification": …, "jarvis_job": … } }
 *
 * - Dosyayı yalnızca **lider sekme** ve yalnızca **ekran açıkken** okur (PHP
 *   açılmaz; değişmediyse 304). Sonuç `BroadcastChannel` ile diğer sekmelere gider.
 *   Liderlik localStorage'daki zaman damgasıyla: lider susarsa görünür sekme devralır.
 * - İş çalışırken (Jarvis) daha sık okunur: `fast(true)`.
 * - Dosyaya ulaşılamazsa yetkili uca (`/live`) düşülür, daha seyrek.
 *
 * Kullanım: `$watch(() => $store.pulse.topics.jarvis_job, …)`.
 */
import { http } from '../services/http.js';

const CHANNEL = 'km-pulse';
const LEADER_KEY = 'km_pulse_leader';
const SLOW_MS = 6000;
const FAST_MS = 2500;
const FALLBACK_MS = 20000;
const LEADER_TTL = 12000;
const MAX_FILE_ERRORS = 3;

export function createPulseStore() {
    const tabId = Math.random().toString(36).slice(2);
    return {
        topics: {},
        ready: false,
        url: null,
        fallback: false,
        fileErrors: 0,
        fastUntil: 0,
        timer: null,
        channel: null,
        busy: false,

        async start() {
            if (this.timer || this.ready) return;
            try {
                const info = await http.get('/live');
                this.url = info.url;
                this.apply(info.topics || {}, false);
            } catch {
                return;
            }
            this.ready = true;
            if ('BroadcastChannel' in window) {
                this.channel = new BroadcastChannel(CHANNEL);
                this.channel.onmessage = (e) => this.apply(e.data?.topics || {}, false);
            }
            document.addEventListener('visibilitychange', () => {
                if (document.visibilityState === 'visible') this.poll();
            });
            this.schedule();
        },

        stop() {
            clearTimeout(this.timer);
            this.timer = null;
            this.channel?.close();
            this.channel = null;
            this.ready = false;
        },

        /** Bir süre sık oku (ör. çalışan iş varken). */
        fast(on = true) {
            this.fastUntil = on ? Date.now() + 5 * 60000 : 0;
            if (on) this.schedule(0);
        },

        schedule(delay) {
            clearTimeout(this.timer);
            const wait = delay ?? (this.fallback ? FALLBACK_MS : Date.now() < this.fastUntil ? FAST_MS : SLOW_MS);
            this.timer = setTimeout(() => this.tick(), wait);
        },

        async tick() {
            if (document.visibilityState === 'visible' && this.isLeader()) await this.poll();
            this.schedule();
        },

        isLeader() {
            try {
                const raw = JSON.parse(localStorage.getItem(LEADER_KEY) || 'null');
                if (!raw || raw.id === tabId || Date.now() - raw.at > LEADER_TTL) {
                    localStorage.setItem(LEADER_KEY, JSON.stringify({ id: tabId, at: Date.now() }));
                    return true;
                }
                return false;
            } catch {
                return true;
            }
        },

        async poll() {
            if (this.busy || !this.ready) return;
            this.busy = true;
            try {
                let topics;
                if (!this.fallback && this.url) {
                    try {
                        const res = await fetch(this.url, { cache: 'no-cache', credentials: 'same-origin' });
                        if (res.status === 404) topics = {};
                        else if (!res.ok) throw new Error(String(res.status));
                        else topics = (await res.json())?.t || {};
                        this.fileErrors = 0;
                    } catch {
                        if (++this.fileErrors >= MAX_FILE_ERRORS) this.fallback = true;
                        return;
                    }
                } else {
                    topics = (await http.get('/live')).topics || {};
                }
                this.apply(topics, true);
            } catch {
                /* ağ yoksa sonraki turda */
            } finally {
                this.busy = false;
            }
        },

        apply(topics, broadcast) {
            let changed = false;
            const next = { ...this.topics };
            for (const [key, value] of Object.entries(topics)) {
                if (next[key] !== value) {
                    next[key] = value;
                    changed = true;
                }
            }
            if (!changed) return;
            this.topics = next;
            if (broadcast) this.channel?.postMessage({ topics });
        },

        version(topic) {
            return this.topics[topic] || 0;
        },
    };
}
