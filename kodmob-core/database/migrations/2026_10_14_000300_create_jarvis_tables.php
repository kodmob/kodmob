<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Jarvis tabloları:
 *
 * - `jarvis_jobs`: uzun süren işler (ör. domain taraması). Parça parça ilerler;
 *   `state` kaldığı yeri (imleç, geri çekilme) tutar.
 * - `jarvis_domains`: domain kontrol sonuçları. `job_id = 0` anlık kontroller.
 *   Aynı ad 24 saat içinde yeniden sorulursa buradan yanıtlanır.
 * - `jarvis_conversations` / `jarvis_messages`: Claude sohbetleri. Mesaj içeriği
 *   API'nin blok biçiminde, değiştirilmeden saklanır (yalnızca eklenir).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('jarvis_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('kind', 40);
            $table->string('title', 190);
            $table->string('status', 20)->default('queued');
            $table->text('input');
            $table->longText('state')->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('processed')->default(0);
            $table->unsignedInteger('found')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->string('error', 500)->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->dateTime('started_at')->nullable();
            $table->dateTime('finished_at')->nullable();
            $table->index(['status', 'id']);
            $table->index(['kind', 'created_at']);
        });

        Schema::create('jarvis_domains', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('job_id')->default(0);
            $table->string('domain', 253);
            $table->string('label', 63);
            $table->string('tld', 63);
            $table->unsignedTinyInteger('length');
            $table->string('status', 20);
            $table->string('source', 10)->default('');
            $table->date('registered_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->string('registrar', 160)->nullable();
            $table->string('rdap_status', 255)->nullable();
            $table->string('note', 255)->nullable();
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('checked_at');
            $table->unique(['job_id', 'domain']);
            $table->index(['domain', 'checked_at']);
            $table->index(['job_id', 'status']);
            $table->index(['status', 'length']);
        });

        Schema::create('jarvis_conversations', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('title', 190);
            $table->string('model', 60);
            $table->boolean('pending')->default(false);
            $table->unsignedInteger('input_tokens')->default(0);
            $table->unsignedInteger('output_tokens')->default(0);
            $table->dateTime('created_at');
            $table->dateTime('updated_at');
            $table->index(['user_id', 'updated_at']);
        });

        Schema::create('jarvis_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('conversation_id');
            $table->string('role', 12);
            $table->longText('content');
            $table->text('meta')->nullable();
            $table->dateTime('created_at');
            $table->index(['conversation_id', 'id']);
        });
    }

    public function down(): void
    {
        foreach (['jarvis_messages', 'jarvis_conversations', 'jarvis_domains', 'jarvis_jobs'] as $table) {
            Schema::dropIfExists($table);
        }
    }
};
