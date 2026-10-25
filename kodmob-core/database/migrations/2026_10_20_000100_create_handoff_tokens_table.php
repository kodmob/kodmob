<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Projeler arası geçiş anahtarları (Modules\Auth\Services\Handoff): ana projenin yöneticisi proje seçiciden alt
 * projeye yeniden giriş yapmadan geçer. Anahtar hedef projenin veritabanında, özetiyle; tek kullanımlık, 60 sn.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('handoff_tokens', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->unsignedBigInteger('user_id');
            $table->string('from_user', 60)->default('');
            $table->dateTime('expires_at');
            $table->dateTime('used_at')->nullable();
            $table->dateTime('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('handoff_tokens');
    }
};
