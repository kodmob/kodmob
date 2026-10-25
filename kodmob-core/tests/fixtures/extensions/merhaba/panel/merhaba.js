/** Merhaba sayfasının bileşeni (şablon: _merhaba.njk). Uç: GET /api/v1/tools/merhaba. */
import { http } from '@/services/http.js';
import { toast } from '@/ui/toast.js';

export function kmMerhaba() {
    return {
        message: '',
        loading: true,

        async init() {
            try {
                this.message = (await http.get('/tools/merhaba')).message;
            } catch (error) {
                toast.error(error);
            } finally {
                this.loading = false;
            }
        },
    };
}
