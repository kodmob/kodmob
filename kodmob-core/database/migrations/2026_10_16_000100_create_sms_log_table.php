<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** SMS gönderim kaydı (App\Core\Sms\SmsLog): numara maskeli, metin saklanmaz. */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sms_log', function (Blueprint $table) {
            $table->id();
            $table->string('recipient', 40);
            $table->string('name', 120)->default('');
            $table->string('kind', 40);
            $table->string('status', 10);
            $table->unsignedTinyInteger('parts')->default(1);
            $table->string('job', 64)->nullable();
            $table->string('error', 500)->nullable();
            $table->dateTime('created_at')->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sms_log');
    }
};
