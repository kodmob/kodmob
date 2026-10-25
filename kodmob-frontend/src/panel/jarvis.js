/**
 * Jarvis → Asistan (/panel/jarvis, /panel/jarvis/5): etkin yapay zekâ sağlayıcısıyla sohbet; Jarvis
 * becerilerini (domain kontrolü, tarama, iş durumu, site özeti) araç olarak
 * kullanır. Sunucu döngüyü kısa parçalarda çalıştırır; yanıt "bekliyor"
 * dönerse ekran kendisi sürdürür (`/continue`).
 *
 * Sağlayıcı, anahtar, model, düşünme düzeyi ve web araması Ayarlar > Yapay zekâ'da (system.js kmAi).
 */
import { href, replaceUrl } from './routes.js';
import { api } from './api.js';
import { fmt } from '../domain/format.js';
import { status } from '../domain/status.js';
import { markdown } from './markdown.js';
import { toast } from '../ui/toast.js';
import { confirmAction } from '../ui/confirm/confirm.js';

const SUGGESTIONS = [
    '.si uzantısında 2 harfli boş ad kaldı mı? Tam tarama başlat.',
    'kodmob, jarvis ve kodlab için .si ve .com boş mu?',
    '.si için okunuşu kolay 4–5 harfli 20 ad öner ve kontrol et.',
    'Sitede kaç yeni talep var, son içerikler neler?',
];
const MAX_CONTINUE = 6;

export function kmJarvis(initialId = null) {
    return {
        // init / destroy'da atananlar burada tanımlı olmalı: Alpine bileşende olmayan özelliği en dıştaki bileşene
        // (panel) yazar; ör. kapanan görünümün `destroyed`'ı sonraki görünüme geçerdi.
        _route: null,
        fmt,
        status,
        markdown,
        suggestions: SUGGESTIONS,
        conversations: [],
        listLoading: true,
        current: null,
        transcript: [],
        pending: false,
        /** Operatör: onay bekleyen yazma önerileri (kodmob-core Jarvis\Assistant\JarvisActions). */
        actions: [],
        deciding: null,
        error: null,
        text: '',
        sending: false,
        loadingConversation: false,
        expanded: {},
        ai: null,


        async init() {
            this._route = (e) => {
                if (e.detail.view === 'jarvis' && e.detail.id && e.detail.id !== this.current?.id) this.open(e.detail.id);
            };
            window.addEventListener('km-route', this._route);
            await Promise.all([this.loadAi(), this.loadList()]);
            const id = initialId || this.conversations[0]?.id || null;
            if (id) await this.open(id, false);
        },

        destroy() {
            window.removeEventListener('km-route', this._route);
        },

        // ------------------------------------------------------------ yapay zekâ (ayarları Ayarlar > Yapay zekâ)
        async loadAi() {
            try {
                this.ai = (await api.jarvis()).ai;
            } catch (error) {
                toast.error(error);
            }
        },

        /** Sağlayıcı ve anahtar Ayarlar > Yapay zekâ'da (km_manage_ai); yetkisi olmayana bilgi verilir. */
        openSettings() {
            if (!this.$store.session.can('km_manage_ai')) {
                toast.info('Yapay zekâ ayarlarını Ayarlar > Yapay zekâ sayfasında yetkili kişi yapar.');
                return;
            }
            window.dispatchEvent(new CustomEvent('km-navigate', { detail: { url: href('settings', { tab: 'ai' }) } }));
        },

        get activeProvider() {
            return this.ai?.providers?.find((p) => p.key === this.ai.provider) || null;
        },

        get modelLabel() {
            const provider = this.activeProvider;
            const model = this.current?.model || provider?.model || '';
            const label = provider?.models?.find((m) => m.id === model)?.label?.replace(/\s*\(.*\)$/, '');
            return label || model;
        },

        // ------------------------------------------------------------ sohbetler
        async loadList() {
            try {
                this.conversations = (await api.conversations()).items || [];
            } catch (error) {
                toast.error(error);
            } finally {
                this.listLoading = false;
            }
        },

        async open(id, updateHash = true) {
            this.loadingConversation = true;
            try {
                this.apply(await api.conversation(id));
                if (updateHash) replaceUrl(href('jarvis', { id }));
                this.$nextTick(() => this.scrollDown());
                if (this.pending) this.continueRun();
            } catch (error) {
                toast.error(error);
            } finally {
                this.loadingConversation = false;
            }
        },

        async create() {
            try {
                const conversation = await api.conversationCreate();
                this.conversations.unshift(conversation);
                this.apply({ conversation, transcript: [], pending: false, error: null });
                replaceUrl(href('jarvis', { id: conversation.id }));
                this.$nextTick(() => this.$refs.composer?.focus());
                return conversation;
            } catch (error) {
                toast.error(error);
                return null;
            }
        },

        async remove(conversation) {
            if (!(await confirmAction({ title: 'Sohbet silinsin mi?', message: `"${conversation.title}" kalıcı olarak silinir.`, confirmText: 'Sil', tone: 'danger' }))) return;
            try {
                await api.conversationDelete(conversation.id);
                this.conversations = this.conversations.filter((c) => c.id !== conversation.id);
                if (this.current?.id === conversation.id) {
                    this.current = null;
                    this.transcript = [];
                    replaceUrl(href('jarvis'));
                }
            } catch (error) {
                toast.error(error);
            }
        },

        apply(res) {
            this.current = res.conversation;
            this.transcript = res.transcript || [];
            this.pending = !!res.pending;
            this.actions = res.actions || [];
            this.error = res.error || null;
            const i = this.conversations.findIndex((c) => c.id === res.conversation.id);
            if (i >= 0) this.conversations[i] = res.conversation;
        },

        // ------------------------------------------------------------ mesaj
        async send(textOverride = null) {
            const text = (textOverride ?? this.text).trim();
            if (!text || this.sending) return;
            if (!this.ai?.configured) {
                this.openSettings();
                return;
            }
            if (!this.current && !(await this.create())) return;
            this.sending = true;
            this.error = null;
            this.text = '';
            // İyimser: mesaj hemen görünsün.
            this.transcript = [...this.transcript, { id: `tmp-${Date.now()}`, role: 'user', text }];
            this.$nextTick(() => this.scrollDown());
            try {
                this.apply(await api.conversationSend(this.current.id, text));
                this.bumpList();
                if (this.pending) await this.continueRun(true);
            } catch (error) {
                this.error = { message: error.message };
                if (error.code === 'km_ai_not_configured') this.openSettings();
                await this.reload();
            } finally {
                this.sending = false;
                this.$nextTick(() => this.scrollDown());
            }
        },

        async continueRun(inner = false) {
            if (!inner) this.sending = true;
            try {
                for (let i = 0; i < MAX_CONTINUE && this.pending && !this.error; i++) {
                    this.apply(await api.conversationContinue(this.current.id));
                    this.$nextTick(() => this.scrollDown());
                }
            } catch (error) {
                this.error = { message: error.message };
            } finally {
                if (!inner) this.sending = false;
            }
        },

        async reload() {
            if (!this.current) return;
            try {
                this.apply(await api.conversation(this.current.id));
            } catch {
                /* ekrandaki hal kalır */
            }
        },

        bumpList() {
            const i = this.conversations.findIndex((c) => c.id === this.current.id);
            if (i > 0) this.conversations.unshift(...this.conversations.splice(i, 1));
        },

        onKey(event) {
            if (event.key === 'Enter' && !event.shiftKey && !event.isComposing) {
                event.preventDefault();
                this.send();
            }
        },

        autosize(el) {
            el.style.height = 'auto';
            el.style.height = `${Math.min(el.scrollHeight, 220)}px`;
        },

        scrollDown() {
            const el = this.$refs.scroller;
            if (el) el.scrollTop = el.scrollHeight;
        },

        // ------------------------------------------------------------ araç kartları
        toggle(part) {
            this.expanded[part.id] = !this.expanded[part.id];
        },

        isOpen(part) {
            return !!this.expanded[part.id];
        },

        toolIcon(name) {
            return {
                domain_check: 'search', domain_scan: 'layers', jarvis_job: 'activity', domain_results: 'list', site_overview: 'bar-chart',
                seo_overview: 'search', seo_not_found: 'alert-circle', seo_broken_links: 'link', content_list: 'list', system_health: 'activity',
                languages_health: 'globe', legal_status: 'file-check', redirect_create: 'swap', post_translate: 'globe',
            }[name] || 'zap';
        },

        /** Bu araç çağrısı onay bekleyen bir öneri mi (Operatör). */
        actionFor(part) {
            return this.actions.find((a) => a.tool_use_id === part.id) || null;
        },

        /** Öneriyi onayla ya da reddet: araç kullanıcının adına çalışır, Jarvis sürer. */
        async decide(action, approve) {
            if (this.deciding) return;
            this.deciding = action.id;
            try {
                this.apply(await api.jarvisDecide(action.id, approve));
                toast.success(approve ? 'Onaylandı; işlem İşlem Geçmişi\'ne sizin adınızla yazıldı.' : 'Reddedildi; işlem yapılmadı.');
                if (this.pending) await this.continueRun(true);
            } catch (error) {
                toast.error(error);
            } finally {
                this.deciding = null;
            }
        },

        /** Araç kartının tek satırlık özeti. */
        toolSummary(part) {
            if (part.result === null) return this.actionFor(part) ? 'onayınızı bekliyor' : 'çalışıyor…';
            if (part.is_error) return typeof part.result === 'string' ? part.result : 'Hata';
            const r = part.result || {};
            switch (part.name) {
                case 'domain_check': {
                    const c = r.counts || {};
                    return `${fmt.number((r.items || []).length)} ad · ${fmt.number(c.available || 0)} boş${c.expiring ? ` · ${c.expiring} düşüyor` : ''}`;
                }
                case 'domain_scan':
                    return r.job ? `İş #${r.job.id} · ${fmt.number(r.job.total)} aday` : '';
                case 'jarvis_job':
                    return r.id ? `#${r.id} · %${fmt.number(r.progress, 1)} · ${fmt.number(r.found)} boş` : `${(r.jobs || []).length} iş`;
                case 'domain_results':
                    return `${fmt.number(r.total || 0)} sonuç`;
                case 'site_overview':
                    return `${fmt.number(r.briefs?.new || 0)} yeni talep`;
                default:
                    return '';
            }
        },

        domainRows(part) {
            const r = part.result || {};
            return r.items || r.found_names || [];
        },

        jobOf(part) {
            const r = part.result || {};
            return r.job || (r.id && r.kind ? r : null);
        },

        pretty(value) {
            return JSON.stringify(value, null, 2);
        },
    };
}
