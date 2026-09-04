<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('ex_users', function (Blueprint $table) {
            // Foto de perfil enviada pelo próprio usuário — coluna separada da
            // `avatar_url` (que guarda a URL externa vinda do login Google).
            $table->string('photo_path')->nullable()->after('avatar_url');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ex_users', function (Blueprint $table) {
            $table->dropColumn('photo_path');
        });
    }
};
