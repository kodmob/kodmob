/**
 * Sayfa bileşenini parçalardan kurar. `{ ...a, ...b }` getter'ları değere
 * çevirir (reaktifliği bozar); burada özellik tanımları olduğu gibi kopyalanır.
 *
 *     function contactPage() {
 *         return compose(contactForm(), { get isValid() { … }, … });
 *     }
 */
export function compose(...parts) {
    const target = {};
    parts.forEach((part) => Object.defineProperties(target, Object.getOwnPropertyDescriptors(part)));
    return target;
}
