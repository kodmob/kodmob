<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Yapay zekâ botlarının ziyaretleri (SEO > Yapay zekâ; Seo\Services\AiVisibility): gün, bot ve adres başına
 * sayı. render.php ham kaydı storage/framework/seo/ai.log'a yazar, saatlik iş ve panel buraya toplar.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_ai_hits', function (Blueprint $table) {
            $table->id();
            $table->date('day');
            $table->string('bot', 40);
            $table->string('path', 255);
            // gün|bot|adres özeti: uzun adreste de bütün veritabanlarında benzersiz dizin.
            $table->char('key_hash', 40)->unique();
            $table->unsignedInteger('hits')->default(0);
            $table->unsignedSmallInteger('status')->default(200);
            $table->dateTime('last_at');
            $table->index('day');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_ai_hits');
    }
};
