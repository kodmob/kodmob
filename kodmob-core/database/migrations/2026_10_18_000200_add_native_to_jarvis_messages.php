<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Jarvis yanıtının sağlayıcıya özgü hali (App\Core\Ai): aynı sağlayıcıyla devam ederken aynen geri
 * gönderilir (OpenAI şifreli düşünme öğeleri, Gemini imzaları). Büyüyebileceği için meta'dan ayrı.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('jarvis_messages', function (Blueprint $table) {
            $table->longText('native')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('jarvis_messages', function (Blueprint $table) {
            $table->dropColumn('native');
        });
    }
};
