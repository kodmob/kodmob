<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * SEO modülü (kodmob-seo-core eklentisinin Laravel karşılığı):
 *
 * - `seo_meta`: kayıt başına SEO (proje, blog yazısı): dil başına başlık, açıklama,
 *   odak kelime; kayıt başına noindex, canonical ve paylaşım görseli.
 * - `seo_redirects`: 301/302/307/410 yönlendirmeleri (kesin ya da düzenli ifade).
 * - `seo_404`: bulunamayan adresler (yol başına toplu: ilk/son görülme, sayı, yönlendiren).
 *
 * Projelerin eski `seo_title` / `seo_description` alanları `seo_meta`'ya taşınır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('seo_meta', function (Blueprint $table) {
            $table->id();
            $table->string('subject_type', 20);
            $table->unsignedBigInteger('subject_id');
            $table->text('locales');
            $table->boolean('noindex')->default(false);
            $table->string('canonical', 500)->default('');
            $table->unsignedBigInteger('image_id')->default(0);
            $table->dateTime('updated_at')->nullable();
            $table->unique(['subject_type', 'subject_id']);
        });

        Schema::create('seo_redirects', function (Blueprint $table) {
            $table->id();
            $table->string('source', 500);
            $table->string('target', 1000)->default('');
            $table->unsignedSmallInteger('code')->default(301);
            $table->boolean('is_regex')->default(false);
            $table->boolean('active')->default(true);
            $table->string('note', 255)->default('');
            $table->unsignedInteger('hits')->default(0);
            $table->dateTime('last_hit_at')->nullable();
            $table->unsignedInteger('position')->default(0);
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->index(['is_regex', 'active']);
            $table->index('source');
        });

        Schema::create('seo_404', function (Blueprint $table) {
            $table->id();
            $table->string('path', 500);
            $table->char('path_hash', 40)->unique();
            $table->unsignedInteger('hits')->default(0);
            $table->string('referrer', 500)->default('');
            $table->boolean('bot')->default(false);
            // Yönlendiren sitenin kendi adresi (kırık iç bağlantı) ve öneri motorunun hedefi.
            $table->boolean('internal')->default(false);
            $table->string('suggestion', 500)->default('');
            $table->string('status', 20)->default('new');
            $table->dateTime('first_seen_at');
            $table->dateTime('last_seen_at');
            $table->index(['status', 'last_seen_at']);
        });

        // Projelerin SEO alanları SEO modülüne taşınır.
        foreach (DB::table('contents')->where('type', 'km_project')->get(['id', 'data']) as $row) {
            $data = json_decode((string) $row->data, true) ?: [];
            $locales = [];
            foreach ((array) ($data['i18n'] ?? []) as $code => $fields) {
                $title = trim((string) ($fields['seo_title'] ?? ''));
                $description = trim((string) ($fields['seo_description'] ?? ''));
                if ($title !== '' || $description !== '') {
                    $locales[$code] = ['title' => $title, 'description' => $description, 'focus' => ''];
                }
                unset($data['i18n'][$code]['seo_title'], $data['i18n'][$code]['seo_description']);
            }
            if ($locales) {
                DB::table('seo_meta')->insert([
                    'subject_type' => 'project',
                    'subject_id' => $row->id,
                    'locales' => json_encode($locales, JSON_UNESCAPED_UNICODE),
                    'updated_at' => now()->format('Y-m-d H:i:s'),
                ]);
            }
            DB::table('contents')->where('id', $row->id)->update(['data' => json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('seo_404');
        Schema::dropIfExists('seo_redirects');
        Schema::dropIfExists('seo_meta');
    }
};
