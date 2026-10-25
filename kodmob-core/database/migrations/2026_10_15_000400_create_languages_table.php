<?php

use App\Core\Content\Languages;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Dil kaydı (done-coklu-dil-raporu.md §4.2): sitenin dilleri koddan çıkıp veritabanına geçer.
 * Bugünkü üç dil tohumlanır; Türkçe varsayılan ve öneksizdir (adreslere Faz 3'te yansır).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('languages', function (Blueprint $table) {
            $table->string('code', 12)->primary();
            $table->string('name', 60);
            $table->string('native', 60);
            $table->string('short', 8);
            $table->string('prefix', 12)->default('');
            $table->string('hreflang', 16);
            $table->string('og', 16);
            $table->string('intl', 16);
            $table->string('dir', 3)->default('ltr');
            $table->string('fallback', 12)->nullable();
            $table->string('flag', 190)->default('');
            $table->boolean('active')->default(false);
            $table->boolean('is_default')->default(false);
            $table->unsignedSmallInteger('position')->default(0);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        $now = now()->format('Y-m-d H:i:s');
        foreach (Languages::FALLBACK as $i => $language) {
            DB::table('languages')->insert(Languages::toRow($language) + ['position' => $i, 'created_at' => $now, 'updated_at' => $now]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('languages');
    }
};
