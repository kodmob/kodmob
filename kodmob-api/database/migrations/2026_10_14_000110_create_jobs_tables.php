<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Arka plan işleri (Laravel veritabanı kuyruğu) ve e-posta gönderim kaydı. Bkz. App\Core\Jobs\Background.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        // Gönderilen ve gönderilemeyen e-postalar (Ayarlar > E-posta). İçerik saklanmaz.
        Schema::create('mail_log', function (Blueprint $table) {
            $table->id();
            $table->string('recipient', 191);
            $table->string('subject', 255);
            $table->string('kind', 40);
            $table->string('status', 10);
            $table->string('error', 500)->nullable();
            $table->unsignedTinyInteger('attempts')->default(1);
            $table->dateTime('created_at')->index();
        });
    }

    public function down(): void
    {
        foreach (['mail_log', 'failed_jobs', 'jobs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
