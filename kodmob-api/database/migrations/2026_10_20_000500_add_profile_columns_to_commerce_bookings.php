<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Rezervasyon profilleri (Modules\Commerce\Booking\BookingProfile): gün birimli rezervasyonun bitişi (teslim günü,
 * hariç) ve seçilen paket. `tour_id` rezerve edilen kaydın kimliğidir (tür hangisi olursa olsun); `persons`
 * kapasiteden düşen miktar (kişi, adet ya da 1 rezervasyon).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_bookings', function (Blueprint $table) {
            $table->string('end_date', 10)->nullable();
            $table->string('package', 60)->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('commerce_bookings', function (Blueprint $table) {
            $table->dropColumn(['end_date', 'package']);
        });
    }
};
