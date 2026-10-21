<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rezervasyonlar (Modules\Commerce\Booking; Mağaza'ya takılı modül). Her satır bir sipariş kaleminin tur ve tarih
 * için ayırdığı yerdir: kapasite iptal / iade edilmemiş siparişlerin kişi sayılarıyla hesaplanır.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('commerce_bookings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('item_id');
            $table->unsignedBigInteger('tour_id');
            $table->string('date', 10);
            $table->integer('adults')->default(0);
            $table->integer('children')->default(0);
            $table->integer('infants')->default(0);
            $table->integer('persons')->default(0);
            $table->dateTime('created_at');
            $table->index(['tour_id', 'date']);
            $table->index('order_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('commerce_bookings');
    }
};
