<?php

use App\Core\Activity\EventGroups;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Çok kullanıcılı panel: roller (yetkiler ve yan menü), kullanıcı durumu,
 * oturum kimliği, genel işlem kaydı alanları, güvenlik olayları ve IP engelleri.
 *
 * - `roles`: hazır roller (system = 1) kodda tanımlıdır (Core\Security\Roles);
 *   yetkileri ve menüsü panelden değiştirilir. Yeni rol panelden eklenir.
 * - `refresh_tokens.session_id`: yenilemede değişmeyen oturum kimliği (cihaz);
 *   `last_seen_at` erişim jetonunun son kullanıldığı an (en çok 5 dakikada bir yazılır).
 * - `events`: `group_key` (İşlem Geçmişi sekmesi), `subject_label` (kaydın adı), `ip`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->string('slug', 40)->primary();
            $table->string('label', 60);
            $table->string('description', 255)->default('');
            $table->text('caps')->nullable();
            $table->text('menu')->nullable();
            $table->boolean('system')->default(false);
            $table->dateTime('created_at')->nullable();
            $table->dateTime('updated_at')->nullable();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->string('phone', 30)->default('')->after('display_name');
            $table->string('status', 20)->default('active')->after('role');
            $table->dateTime('deactivated_at')->nullable()->after('status');
            $table->dateTime('password_changed_at')->nullable()->after('deactivated_at');
            $table->unsignedBigInteger('created_by')->nullable()->after('password_changed_at');
            $table->index('role');
        });

        Schema::table('refresh_tokens', function (Blueprint $table) {
            $table->char('session_id', 32)->default('')->after('jti');
            $table->dateTime('last_seen_at')->nullable()->after('expires_at');
            $table->index('session_id');
        });

        Schema::table('events', function (Blueprint $table) {
            $table->string('group_key', 20)->default('other')->after('event_type');
            $table->string('subject_label', 190)->default('')->after('subject_id');
            $table->string('ip', 45)->default('')->after('user_id');
            $table->index(['group_key', 'created_at']);
            $table->index(['user_id', 'created_at']);
        });

        foreach (['contents', 'posts'] as $name) {
            Schema::table($name, function (Blueprint $table) {
                $table->unsignedBigInteger('deleted_by')->nullable()->after('deleted_at');
            });
        }

        Schema::create('security_events', function (Blueprint $table) {
            $table->id();
            $table->string('type', 32);
            $table->string('ip', 45)->default('');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('username', 190)->default('');
            $table->string('details', 500)->default('');
            $table->string('user_agent', 255)->default('');
            $table->dateTime('created_at');
            $table->index(['type', 'created_at']);
            $table->index(['ip', 'created_at']);
            $table->index('created_at');
        });

        Schema::create('ip_blocks', function (Blueprint $table) {
            $table->id();
            $table->string('ip', 64)->unique();
            $table->string('reason', 255)->default('');
            $table->string('source', 20)->default('manual');
            $table->dateTime('expires_at')->nullable();
            $table->unsignedInteger('hits')->default(0);
            $table->dateTime('last_hit_at')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->dateTime('created_at');
        });

        // Eski kurulum: tek yönetici (role = admin) kalır; açık oturumlara kimlik,
        // eski olaylara İşlem Geçmişi grubu yazılır.
        DB::table('users')->whereNull('password_changed_at')->update(['password_changed_at' => DB::raw('created_at')]);
        foreach (DB::table('refresh_tokens')->where('session_id', '')->get(['id', 'token_hash']) as $row) {
            DB::table('refresh_tokens')->where('id', $row->id)->update(['session_id' => substr((string) $row->token_hash, 0, 32)]);
        }
        foreach (DB::table('events')->distinct()->pluck('event_type') as $type) {
            DB::table('events')->where('event_type', $type)->update(['group_key' => EventGroups::of((string) $type)]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('ip_blocks');
        Schema::dropIfExists('security_events');
        foreach (['contents', 'posts'] as $name) {
            Schema::table($name, fn (Blueprint $table) => $table->dropColumn('deleted_by'));
        }
        Schema::table('events', function (Blueprint $table) {
            $table->dropIndex(['group_key', 'created_at']);
            $table->dropIndex(['user_id', 'created_at']);
            $table->dropColumn(['group_key', 'subject_label', 'ip']);
        });
        Schema::table('refresh_tokens', function (Blueprint $table) {
            $table->dropIndex(['session_id']);
            $table->dropColumn(['session_id', 'last_seen_at']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['role']);
            $table->dropColumn(['phone', 'status', 'deactivated_at', 'password_changed_at', 'created_by']);
        });
        Schema::dropIfExists('roles');
    }
};
