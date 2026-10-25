<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mağaza (Modules\Commerce; docs/done-commerce-raporu.md). Tutarlar mağazanın para biriminde **kuruş** (en küçük birim)
 * olarak tam sayıdır: üç veritabanında aynı hesap, yuvarlama farkı yok. Ürünler içerik türüdür (`contents`).
 *
 *   commerce_carts         ziyaretçinin sepeti (çerezdeki anahtarın özeti; satırlar JSON, fiyat her adımda yeniden)
 *   commerce_orders        sipariş: müşteri, adres, tutarlar, durum; müşterinin bağlantı anahtarının özeti
 *   commerce_order_items   siparişteki ürünün o anki hali (ad, birim fiyat, KDV)
 *   commerce_payments      ödeme denemeleri (havale, kapıda, online sağlayıcılar)
 *   commerce_coupons       indirim kuponları; kullanımı sipariş verilince sayılır (commerce_coupon_uses)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_carts', function (Blueprint $table) {
            $table->id();
            $table->char('token_hash', 64)->unique();
            $table->text('items');
            $table->string('coupon', 40)->default('');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->index('updated_at');
        });

        Schema::create('commerce_orders', function (Blueprint $table) {
            $table->id();
            $table->string('number', 30)->unique();
            $table->char('token_hash', 64);
            $table->string('status', 20)->default('pending');
            $table->string('payment_method', 30)->default('');
            $table->string('payment_status', 20)->default('unpaid');
            $table->char('currency', 3);
            $table->bigInteger('subtotal')->default(0);
            $table->bigInteger('discount')->default(0);
            $table->bigInteger('shipping')->default(0);
            $table->bigInteger('fee')->default(0);
            $table->bigInteger('tax')->default(0);
            $table->bigInteger('total')->default(0);
            $table->string('coupon', 40)->default('');
            $table->string('customer_name', 120);
            $table->string('customer_email', 190);
            $table->string('customer_phone', 40)->default('');
            $table->text('address');
            $table->text('note');
            $table->string('locale', 10)->default('');
            $table->text('data');
            $table->dateTime('paid_at')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->index(['status', 'created_at']);
            $table->index('customer_email');
        });

        Schema::create('commerce_order_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('product_id')->nullable();
            $table->string('title', 190);
            $table->string('sku', 60)->default('');
            $table->bigInteger('unit_price');
            $table->integer('quantity');
            $table->integer('tax_rate')->default(0);
            $table->bigInteger('total');
            $table->text('data');
            $table->index('order_id');
        });

        Schema::create('commerce_payments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->string('provider', 30);
            $table->string('status', 20)->default('pending');
            $table->bigInteger('amount');
            $table->char('currency', 3);
            $table->string('reference', 190)->default('');
            $table->text('data');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->index('order_id');
            $table->index(['provider', 'reference']);
        });

        Schema::create('commerce_coupons', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            $table->string('type', 10)->default('percent');
            $table->bigInteger('value');
            $table->bigInteger('min_total')->default(0);
            $table->integer('usage_limit')->default(0);
            $table->integer('used')->default(0);
            $table->dateTime('starts_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->boolean('active')->default(true);
            $table->string('note', 190)->default('');
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
        });

        Schema::create('commerce_coupon_uses', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('coupon_id');
            $table->unsignedBigInteger('order_id');
            $table->dateTime('created_at');
            $table->index('coupon_id');
        });
    }

    public function down(): void
    {
        foreach (['commerce_coupon_uses', 'commerce_coupons', 'commerce_payments', 'commerce_order_items', 'commerce_orders', 'commerce_carts'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
