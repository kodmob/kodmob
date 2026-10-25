<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Yenileme jetonu döndürmede kısa ödünç süresi (TokenService::GRACE): bir önceki jeton, döndürmeden
 * sonra kısa bir süre daha geçer. Yanıtı yolda kaybolan (sayfa geçişi, bağlantı kopması) istemci
 * yeni çerezi alamasa da oturumu düşmez.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refresh_tokens', function (Blueprint $table) {
            $table->char('previous_hash', 64)->nullable()->index();
            $table->dateTime('rotated_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('refresh_tokens', function (Blueprint $table) {
            $table->dropIndex(['previous_hash']);
            $table->dropColumn(['previous_hash', 'rotated_at']);
        });
    }
};
