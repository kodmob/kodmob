<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Anlık bildirim (Web Push) abonelikleri: bir satır bir cihazdaki bir tarayıcıdır (Core\Push\PushSubscriptions).
 * Sahibi panel kullanıcısı (`user`) ya da site üyesi (`member`). Adres uzun olabilir; tekliği özetinden.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('push_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->string('owner_type', 20);
            $table->unsignedBigInteger('owner_id');
            $table->text('endpoint');
            $table->string('endpoint_hash', 64)->unique();
            $table->string('p256dh', 120);
            $table->string('auth', 40);
            $table->string('user_agent', 255)->nullable();
            $table->unsignedSmallInteger('failures')->default(0);
            $table->dateTime('created_at');
            $table->dateTime('last_used_at')->nullable();
            $table->index(['owner_type', 'owner_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('push_subscriptions');
    }
};
