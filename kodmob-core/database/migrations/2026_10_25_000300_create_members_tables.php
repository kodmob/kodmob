<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Site üyeliği (Modules\Members): panel kullanıcılarından ayrı tablo ve oturum. Üye e-posta + şifreyle ya da Google
 * ile girer; oturumu HttpOnly çerezdedir (`member_sessions`, anahtarın özeti). Doğrulama ve şifre yenileme anahtarları
 * tek kullanımlık, süreli (`member_tokens`, özeti saklanır).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('members', function (Blueprint $table) {
            $table->id();
            $table->string('name', 120);
            $table->string('email', 190)->unique();
            $table->string('password')->nullable();
            $table->string('google_id', 64)->nullable()->unique();
            $table->string('status', 20)->default('active');
            $table->string('locale', 10)->default('');
            $table->dateTime('email_verified_at')->nullable();
            $table->dateTime('consent_at')->nullable();
            $table->dateTime('last_login_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });

        Schema::create('member_sessions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('member_id')->index();
            $table->char('token_hash', 64)->unique();
            $table->string('ip', 45)->default('');
            $table->string('user_agent', 255)->default('');
            $table->dateTime('created_at');
            $table->dateTime('expires_at')->index();
        });

        Schema::create('member_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('member_id')->index();
            $table->string('purpose', 20);
            $table->char('token_hash', 64)->unique();
            $table->dateTime('created_at');
            $table->dateTime('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('member_tokens');
        Schema::dropIfExists('member_sessions');
        Schema::dropIfExists('members');
    }
};
