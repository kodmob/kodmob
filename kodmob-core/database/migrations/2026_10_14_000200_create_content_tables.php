<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * İçerik: görseller, çok dilli içerik (projeler/hizmetler/referanslar), blog,
 * talepler, panel bildirimleri ve adres geçmişi. Silinen içerik çöpe gider (`deleted_at`).
 */
return new class extends Migration
{
    public function up(): void
    {
        // Görsel kitaplığı: dosyalar <site kökü>/media/<path>/<file>, ara boyutlar `sizes` (JSON).
        Schema::create('media', function (Blueprint $table) {
            $table->id();
            $table->string('path', 20);
            $table->string('file', 190);
            $table->string('mime', 40);
            $table->unsignedInteger('width')->default(0);
            $table->unsignedInteger('height')->default(0);
            $table->unsignedBigInteger('size')->default(0);
            $table->text('sizes');
            $table->text('alts');
            $table->string('origin', 20)->default('panel');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at')->nullable();
            $table->index('file');
            $table->index('created_at');
        });

        // Projeler, hizmetler, referanslar: `type` = km_project | km_service | km_reference, veri JSON
        // (`data.i18n` dil başına alanlar, `data.locales` eklenmiş diller ve dil başına yayın; Core\Content\ContentType).
        Schema::create('contents', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->string('status', 20)->default('draft');
            $table->integer('position')->default(0);
            $table->string('title', 190)->default('');
            $table->longText('data');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->dateTime('deleted_at')->nullable();
            $table->index(['type', 'deleted_at', 'position']);
        });

        // Blog: her yazı tek dilde; aynı `translation_group`daki (UUID) yazılar birbirinin çevirisi.
        // `source_hash`: çevrildiği andaki kaynak yazının metin özeti ("Güncellenmeli").
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->string('locale', 5);
            $table->string('translation_group', 36)->nullable();
            $table->string('source_hash', 16)->nullable();
            $table->string('slug', 190);
            $table->string('title', 255);
            $table->text('excerpt');
            $table->longText('html');
            $table->unsignedBigInteger('cover_id')->default(0);
            $table->string('category', 40)->default('web');
            $table->string('status', 20)->default('draft');
            $table->dateTime('published_at');
            $table->unsignedBigInteger('author_id')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->dateTime('deleted_at')->nullable();
            $table->index(['locale', 'status', 'published_at']);
            $table->index(['locale', 'slug']);
            $table->index('translation_group');
        });

        // Adres geçmişi: adresi (ya da yazının dili) değişen kaydın eski adresi → sitede 301
        // (Core\Content\SlugHistory). Adres yeniden kullanılırsa satır silinir.
        Schema::create('slug_history', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('locale', 12);
            $table->string('slug', 190);
            $table->unsignedBigInteger('record_id');
            $table->dateTime('created_at');
            $table->unique(['type', 'locale', 'slug']);
        });

        // Proje brifi / iletişim talepleri. IP saklanmaz; hız sınırı için tuzlu özeti.
        Schema::create('briefs', function (Blueprint $table) {
            $table->id();
            $table->string('locale', 5);
            $table->string('name', 120);
            $table->string('email', 190);
            $table->string('phone', 40)->nullable();
            $table->string('company', 160)->nullable();
            $table->text('services');
            $table->string('budget', 60)->nullable();
            $table->string('timeline', 60)->nullable();
            $table->text('message');
            $table->text('estimate')->nullable();
            $table->string('source', 40)->default('brief');
            $table->string('status', 20)->default('new');
            $table->char('ip_hash', 64);
            $table->string('user_agent', 255)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->index(['status', 'created_at']);
            $table->index(['ip_hash', 'created_at']);
            $table->index('email');
        });

        // Panel bildirimleri (üst bardaki zil); 90 gün sonra silinir.
        Schema::create('notifications', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('type', 60);
            $table->string('tone', 20)->default('primary');
            $table->string('title', 190);
            $table->string('message', 500)->nullable();
            $table->string('url', 255)->nullable();
            $table->text('data')->nullable();
            $table->boolean('is_read')->default(false);
            $table->dateTime('read_at')->nullable();
            $table->dateTime('created_at');
            $table->index(['user_id', 'is_read', 'id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        foreach (['notifications', 'briefs', 'slug_history', 'posts', 'contents', 'media'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
