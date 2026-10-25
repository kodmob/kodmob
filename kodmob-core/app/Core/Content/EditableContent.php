<?php

namespace App\Core\Content;

/**
 * Panelde liste + form ile yönetilen içerik (projeler, hizmetler, referanslar,
 * blog). Panel bütün türleri aynı şema biçimiyle çizer (bkz. Fields).
 *
 * Tür kendini tanımlar (ad, kayıt adı, yetki, etiket); API rotaları, işlem kaydı, değişiklik
 * akışı, Silinenler, yetki listesi ve yedek bu bilgiyi kayıttan (Types) türetir.
 */
interface EditableContent
{
    /** API ve panel adı (çoğul): `projects` → /admin/content/projects. */
    public function name(): string;

    /** İşlem kaydı, değişiklik akışı ve Silinenler'deki adı (tekil): `project`. */
    public function recordKind(): string;

    /** Ekleme ve düzenleme yetkisi: `km_manage_projects`. */
    public function capability(): string;

    /** Yetkinin rol düzenleyicideki adı: "Projeleri ekleme ve düzenleme". */
    public function capabilityLabel(): string;

    /** @return array{0:string,1:string} [çoğul, tekil] etiket: ['Projeler', 'Proje'] */
    public function labels(): array;

    public function schema(): array;

    /** @return list<array> */
    public function adminList(): array;

    public function adminItem(int $id): array;

    public function blankItem(): array;

    /** @return int kayıt kimliği */
    public function save(?int $id, array $input): int;

    public function delete(int $id): void;
}
