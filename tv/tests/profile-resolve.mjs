/**
 * Node çözümleme kancası (profile-hook.mjs kaydeder): `@profile/x` → <etkin profil>/x; araç paketlerinin
 * sanal modülü (`virtual:kodmob-extensions`, Vite'ta kmExtensions) aynı listeden üretilir.
 */
import path from 'node:path';
import { pathToFileURL } from 'node:url';
import { PROFILE_DIR } from '../profile.js';
import { VIRTUAL, extensionPanels, extensionsSource } from '../vite/extensions.js';

const TOOLS_URL = 'kodmob:extensions';

export async function resolve(specifier, context, next) {
    if (specifier.startsWith('@profile/')) {
        return next(pathToFileURL(path.join(PROFILE_DIR, specifier.slice('@profile/'.length))).href, context);
    }
    if (specifier === VIRTUAL) return { url: TOOLS_URL, shortCircuit: true };
    return next(specifier, context);
}

export async function load(url, context, next) {
    if (url === TOOLS_URL) {
        return { format: 'module', source: extensionsSource(extensionPanels(), (file) => pathToFileURL(file).href), shortCircuit: true };
    }
    return next(url, context);
}
