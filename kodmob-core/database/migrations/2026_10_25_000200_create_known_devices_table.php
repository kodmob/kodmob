<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Kişinin daha önce giriş yaptığı cihazlar (Modules\Security\NewDeviceAlert): tarayıcı + ağın özeti. Bilinmeyen
 * cihazdan giriş olunca kişiye e-posta gider. Özet geri çevrilemez (IP ve tarayıcı adı saklanmaz).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('known_devices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->char('fingerprint', 64);
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->unique(['user_id', 'fingerprint']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('known_devices');
    }
};
