<?php

/*
 * Merhaba aracının uçları: /api/v1 altında yüklenir; araç bu projede kapalıyken 404 (`module:merhaba`).
 * Kurallar çatınınkiyle aynı: panel ucu `auth.jwt` + `cap:`; yazma rotasının adı (->name('merhaba.x'))
 * modülün events()'inde tanımlı olmalı (İşlem Geçmişi).
 */

use Illuminate\Support\Facades\Route;
use KodmobTools\Merhaba\MerhabaController;
use KodmobTools\Merhaba\MerhabaModule;

Route::middleware(['auth.jwt', 'activity', 'cap:km_access_panel', 'cap:'.MerhabaModule::USE])->prefix('tools/merhaba')->group(function () {
    Route::get('/', [MerhabaController::class, 'show']);
});
