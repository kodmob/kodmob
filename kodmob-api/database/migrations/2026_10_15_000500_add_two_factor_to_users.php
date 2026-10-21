<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * İki adımlı doğrulama (TOTP; App\Modules\Auth\Services\TwoFactor): gizli anahtar APP_KEY ile
 * şifreli, yedek kodların yalnızca özetleri, son kullanılan zaman adımı (aynı kod iki kez geçmez).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('two_factor_secret')->nullable();
            $table->text('two_factor_recovery')->nullable();
            $table->dateTime('two_factor_confirmed_at')->nullable();
            $table->unsignedBigInteger('two_factor_last_step')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_secret', 'two_factor_recovery', 'two_factor_confirmed_at', 'two_factor_last_step']);
        });
    }
};
