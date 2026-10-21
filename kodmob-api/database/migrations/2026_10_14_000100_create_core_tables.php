<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Çekirdek tablolar: kullanıcılar ve oturumlar, seçenekler, ayarlar, olay defteri.
 * Tarihler DATETIME ve site saatiyledir (APP_TIMEZONE); MySQL 5.7'de NOT NULL
 * TIMESTAMP sütunları kendiliğinden değişebildiği için TIMESTAMP kullanılmaz.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('username', 60)->unique();
            $table->string('email', 190)->unique();
            $table->string('display_name', 120);
            $table->string('password');
            $table->string('role', 20)->default('admin');
            $table->rememberToken();
            $table->dateTime('last_login_at')->nullable();
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        // Yenileme jetonları: düz metin hiç saklanmaz, yalnızca SHA-256 özeti.
        Schema::create('refresh_tokens', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->char('token_hash', 64)->unique();
            $table->string('jti', 64)->index();
            $table->dateTime('started_at');
            $table->dateTime('expires_at')->index();
            $table->string('ip', 45)->default('');
            $table->string('user_agent', 255)->default('');
            $table->dateTime('created_at')->nullable();
        });

        // Çıkışta iptal edilen erişim jetonları (süresi dolana kadar).
        Schema::create('revoked_tokens', function (Blueprint $table) {
            $table->string('jti', 64)->primary();
            $table->dateTime('expires_at')->index();
        });

        // Yapılandırılmış değerler (sayfa metinleri, teklif fiyatları, Jarvis ayarı…): JSON.
        Schema::create('options', function (Blueprint $table) {
            $table->string('name', 191)->primary();
            $table->longText('value');
            $table->dateTime('updated_at')->nullable();
        });

        // Tanımlı ayarların değerleri (tanımlar kodda: Core\Settings).
        Schema::create('settings', function (Blueprint $table) {
            $table->string('setting_key', 100);
            $table->string('scope', 40)->default('global');
            $table->text('value');
            $table->unsignedBigInteger('updated_by')->nullable();
            $table->dateTime('updated_at');
            $table->primary(['setting_key', 'scope']);
        });

        // İzlenebilirlik defteri: satırlar yalnızca eklenir.
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->string('event_type', 80);
            $table->string('subject_type', 40);
            $table->unsignedBigInteger('subject_id');
            $table->text('related')->nullable();
            $table->text('payload');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->dateTime('created_at');
            $table->index(['subject_type', 'subject_id']);
            $table->index(['event_type', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('event_links', function (Blueprint $table) {
            $table->foreignId('event_id')->constrained('events')->cascadeOnDelete();
            $table->string('object_type', 40);
            $table->unsignedBigInteger('object_id');
            $table->primary(['event_id', 'object_type', 'object_id']);
            $table->index(['object_type', 'object_id']);
        });
    }

    public function down(): void
    {
        foreach (['event_links', 'events', 'settings', 'options', 'revoked_tokens', 'refresh_tokens', 'users'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
