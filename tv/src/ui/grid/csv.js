/**
 * CSV üretimi (dfGrid). Excel'in Türkçe ayarlarıyla doğrudan açılır: UTF-8 BOM,
 * noktalı virgül ayırıcı, ondalık virgül. Formül enjeksiyonuna karşı `=`, `+`,
 * `-`, `@` ile başlayan metinlerin başına tek tırnak eklenir.
 */
const NUM = new Intl.NumberFormat('tr-TR', { useGrouping: false, maximumFractionDigits: 3 });

function cell(value) {
    if (value === null || value === undefined) return '';
    let text;
    if (typeof value === 'number') text = Number.isFinite(value) ? NUM.format(value) : '';
    else if (typeof value === 'boolean') text = value ? 'Evet' : 'Hayır';
    else text = String(value);
    if (/^[=+\-@]/.test(text) && Number.isNaN(Number(text.replace(',', '.')))) text = `'${text}`;
    return /[";\n\r]/.test(text) ? `"${text.replace(/"/g, '""')}"` : text;
}

/**
 * @param {{label:string, value:(row:object)=>any}[]} columns
 * @param {object[]} rows
 */
export function toCsv(columns, rows) {
    const lines = [columns.map((c) => cell(c.label)).join(';')];
    rows.forEach((row) => lines.push(columns.map((c) => cell(c.value(row))).join(';')));
    return '﻿' + lines.join('\r\n');
}

export function downloadCsv(filename, csv) {
    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8' });
    const url = URL.createObjectURL(blob);
    const a = document.createElement('a');
    a.href = url;
    a.download = filename;
    document.body.appendChild(a);
    a.click();
    a.remove();
    setTimeout(() => URL.revokeObjectURL(url), 1000);
}
