/**
 * SMS metin kuralları: kodmob-core App\Core\Sms\SmsText ile aynı hesap (ortak vektörler:
 * kodmob-core/tests/fixtures/sms.json, kodmob-frontend/tests/sms.test.mjs). Panel yazarken karakter ve
 * boy sayısını gösterir; asıl denetim sunucudadır.
 *
 * - ş ğ ı İ ç içeren metin Türkçe kodlamayla gider: boy 155, birleşik mesajda parça başına 150
 *   (GSM: 160 / 153). { } [ ] ~ ^ | \ € iki karakter sayılır.
 * - Tipografik tırnak, uzun tire, üç nokta, şapkalı harf SMS'e uygun karşılığına çevrilir.
 * - GSM alfabesi + Türkçe harfler dışında kalan (emoji vb.) gönderilmez: `unsupported`.
 */
export const MAX_PARTS = 6;

const GSM = '@£$¥èéùìòÇ\nØø\rÅåΔ_ΦΓΛΩΠΨΣΘΞÆæßÉ !"#¤%&\'()*+,-./0123456789:;<=>?¡ABCDEFGHIJKLMNOPQRSTUVWXYZÄÖÑÜ§¿abcdefghijklmnopqrstuvwxyzäöñüà';
const GSM_EXTENDED = '^{}\\[~]|€';
const TURKISH = 'ŞşĞğİıç';

const REPLACE = [
    ['\r\n', '\n'], ['\t', ' '], [' ', ' '], [' ', ' '], [' ', ' '],
    ['“', '"'], ['”', '"'], ['„', '"'], ['«', '"'], ['»', '"'], ['″', '"'],
    ['‘', "'"], ['’', "'"], ['‚', "'"], ['′', "'"], ['`', "'"], ['´', "'"],
    ['–', '-'], ['—', '-'], ['―', '-'], ['−', '-'], ['•', '-'], ['…', '...'],
    ['â', 'a'], ['Â', 'A'], ['î', 'i'], ['Î', 'I'], ['û', 'u'], ['Û', 'U'],
];

export function clean(text) {
    let out = String(text ?? '');
    for (const [from, to] of REPLACE) out = out.split(from).join(to);
    return out.trim();
}

/** @returns {{ text: string, encoding: ''|'tr', length: number, parts: number, perPart: number, unsupported: string[] }} */
export function analyze(text) {
    const value = clean(text);
    let length = 0;
    let turkish = false;
    const unsupported = new Set();
    for (const char of value) {
        if (GSM_EXTENDED.includes(char)) length += 2;
        else if (GSM.includes(char)) length += 1;
        else if (TURKISH.includes(char)) {
            length += 1;
            turkish = true;
        } else unsupported.add(char);
    }
    const [single, multi] = turkish ? [155, 150] : [160, 153];
    const parts = length === 0 ? 0 : length <= single ? 1 : Math.ceil(length / multi);
    return { text: value, encoding: turkish ? 'tr' : '', length, parts, perPart: parts > 1 ? multi : single, unsupported: [...unsupported] };
}
