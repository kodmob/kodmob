<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Bülten (isteğe bağlı araç `newsletter`; Modules\Newsletter): aboneler (çift onaylı; onayın tarihi ve IP özeti
 * ispat için saklanır) ve bültenler (gönderim imleci: abone kimliği sırasıyla parça parça, kaldığı yerden sürer).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('newsletter_subscribers', function (Blueprint $table) {
            $table->id();
            $table->string('email', 190)->unique();
            $table->string('name', 120)->nullable();
            $table->string('locale', 10);
            // pending (onay bekliyor) | active | unsubscribed
            $table->string('status', 20)->index();
            // Onay ve abonelikten çıkma bağlantısının anahtarı (her yeni abonelikte değişir).
            $table->string('token', 64)->unique();
            $table->string('source', 20);
            $table->string('ip_hash', 64)->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->dateTime('confirmed_at')->nullable();
            $table->dateTime('unsubscribed_at')->nullable();
        });

        Schema::create('newsletter_campaigns', function (Blueprint $table) {
            $table->id();
            $table->string('subject', 200);
            $table->longText('body');
            // Hedef dil; boş: bütün dillerdeki aboneler.
            $table->string('locale', 10)->nullable();
            // draft | sending | paused | sent
            $table->string('status', 20)->index();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('sent')->default(0);
            $table->unsignedInteger('failed')->default(0);
            // Gönderilen son abone kimliği ve başlangıçtaki en büyük kimlik (sonradan gelen abone bu bülteni almaz).
            $table->unsignedBigInteger('cursor')->default(0);
            $table->unsignedBigInteger('last_id')->default(0);
            // Art arda başarısız gönderim (e-posta sunucusu hatası): sınırda bülten durur, imleç dizinin başına döner.
            $table->unsignedSmallInteger('streak')->default(0);
            $table->unsignedBigInteger('streak_from')->default(0);
            $table->string('error', 300)->nullable();
            // Aynı anda iki gönderim parçası çalışmasın (zamanlanmış görev ve panel).
            $table->dateTime('locked_until')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('newsletter_campaigns');
        Schema::dropIfExists('newsletter_subscribers');
    }
};
