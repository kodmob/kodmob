<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sayfa düzenleyici (Modules\Site\Blocks\Layouts): bölge başına yayındaki ve taslak blok listesi (JSON), yayın
 * geçmişi (geri almak için; bölge başına son 20).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('page_layouts', function (Blueprint $table) {
            $table->id();
            $table->string('zone', 120)->unique();
            $table->longText('published')->nullable();
            $table->longText('draft')->nullable();
            $table->unsignedBigInteger('draft_by')->nullable();
            $table->dateTime('draft_at')->nullable();
            $table->unsignedBigInteger('published_by')->nullable();
            $table->dateTime('published_at')->nullable();
        });

        Schema::create('page_layout_revisions', function (Blueprint $table) {
            $table->id();
            $table->string('zone', 120);
            $table->longText('blocks');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->dateTime('created_at');
            $table->index(['zone', 'id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('page_layout_revisions');
        Schema::dropIfExists('page_layouts');
    }
};
