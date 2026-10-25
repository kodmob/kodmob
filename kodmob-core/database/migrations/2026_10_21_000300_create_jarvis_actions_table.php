<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jarvis Operatör (Modules\Jarvis\Assistant\JarvisActions): yazma aracının önerisi kullanıcı onaylayana kadar bekler.
 * Aynı turdaki okuma araçlarının sonuçları da (`deferred`) burada durur; karar verilince hepsi tek araç sonucu mesajı
 * olarak sohbete eklenir.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jarvis_actions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id')->index();
            $table->unsignedBigInteger('user_id');
            $table->string('tool', 60);
            $table->string('tool_use_id', 120);
            $table->longText('input');
            $table->string('summary', 300);
            $table->longText('deferred');
            // pending | approved | rejected | failed
            $table->string('status', 20);
            $table->text('result')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('decided_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('jarvis_actions');
    }
};
