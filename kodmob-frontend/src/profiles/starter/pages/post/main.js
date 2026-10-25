import { startPage } from '@/app.js';
import { copyText } from '@/ui/clipboard.js';
import { toast } from '@/ui/toast.js';
import { t } from '@/i18n/client.js';

/** Yazıyı paylaş: bağlantıyı kopyala (LinkedIn ve X düz bağlantıdır). */
function postShare() {
    return {
        async copy() {
            if (await copyText(window.location.href)) toast.success(t('post.copied'));
        },
    };
}

startPage({ components: { postShare } });
