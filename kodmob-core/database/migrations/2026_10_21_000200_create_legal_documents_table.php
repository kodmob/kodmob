<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Yasal belgelerin panelden özelleştirilmiş halleri (Modules\Site\Legal\LegalPages): belge ve dil başına temizlenmiş
 * HTML. Satır yoksa belge şablondan (km-legal.json) çizilir; "Şablona dön" satırı siler.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('legal_documents', function (Blueprint $table) {
            $table->id();
            $table->string('name', 60);
            $table->string('locale', 10);
            $table->longText('body');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');
            $table->unique(['name', 'locale']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('legal_documents');
    }
};
