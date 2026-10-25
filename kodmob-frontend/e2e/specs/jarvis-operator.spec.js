/**
 * Jarvis Operatör (kodmob-core Jarvis\Assistant\JarvisActions): yazma önerisi onay kartı olarak görünür, mesaj kutusu
 * kapanır; Onayla deyince araç kullanıcının adına panel ucunu çağırır (yönlendirme eklenir). Uçtan uca ortamda yapay
 * zekâ yok: sohbet, Jarvis'in araç çağrısı ve öneri veritabanına doğrudan yazılır (modelin sonraki cevabı gelmez).
 */
import { execFileSync } from 'node:child_process';
import { test, expect } from '@playwright/test';
import { apiClient, consoleErrors, login, stamp, requires } from '../helpers.js';
import { DB, PHP } from '../env.js';

const SEED = `
$db = new PDO('sqlite:' . $argv[1]);
$now = date('Y-m-d H:i:s');
$uid = $db->query("select id from users where username = 'e2e'")->fetchColumn();
$db->prepare('insert into jarvis_conversations (user_id, title, model, pending, input_tokens, output_tokens, created_at, updated_at) values (?, ?, ?, 0, 0, 0, ?, ?)')->execute([$uid, 'Operatör ' . $argv[2], 'claude', $now, $now]);
$cid = $db->lastInsertId();
$input = ['source' => '/e2e-eski-' . $argv[2] . '/', 'target' => '/blog/', 'code' => 301];
$msg = $db->prepare('insert into jarvis_messages (conversation_id, role, content, meta, created_at) values (?, ?, ?, ?, ?)');
$msg->execute([$cid, 'user', json_encode('Kırık adresi düzelt.'), '{}', $now]);
$msg->execute([$cid, 'assistant', json_encode([['type' => 'text', 'text' => 'Bu adresi bloga yönlendirmeyi öneriyorum.'], ['type' => 'tool_use', 'id' => 'toolu_e2e', 'name' => 'redirect_create', 'input' => $input]]), '{"stop_reason":"tool_use"}', $now]);
$db->prepare("insert into jarvis_actions (conversation_id, user_id, tool, tool_use_id, input, summary, deferred, status, created_at) values (?, ?, 'redirect_create', 'toolu_e2e', ?, ?, '[]', 'pending', ?)")
    ->execute([$cid, $uid, json_encode($input), 'Yönlendirme ekle: ' . $input['source'] . ' → /blog/ (301)', $now]);
echo $cid;
`;

test('Jarvis Operatör: öneri onay kartı, onaylanınca kullanıcının adına çalışır', async ({ page, request }) => {
    await requires(request, { modules: ['jarvis'] });
    const errors = consoleErrors(page);
    const tag = stamp();
    const id = execFileSync(PHP, ['-r', SEED, '--', DB, tag]).toString().trim();
    await login(page);
    await page.goto(`/panel/jarvis/${id}`);

    const card = page.locator('[data-testid=jarvis-action]');
    await expect(card).toContainText(`Yönlendirme ekle: /e2e-eski-${tag}/ → /blog/ (301)`);
    await expect(page.locator('textarea[aria-label=Mesaj]')).toBeDisabled();
    await card.locator('[data-testid=jarvis-approve]').click();
    await expect(card).toHaveCount(0);

    const api = await apiClient(request);
    const redirects = (await api.get(`/seo/redirects?q=e2e-eski-${tag}`)).json;
    expect(JSON.stringify(redirects)).toContain(`/e2e-eski-${tag}`);
    expect(errors()).toEqual([]);
});
