<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Quem criou o vínculo "quem paga por quem" (feature 20261110-quem-paga-se-declara-e-credor-recusa,
     * plan §1, D3): o devedor, quando escolhe a lista dele, ou o próprio responsável, quando se declara.
     * Nula nos vínculos que já existem, que são todos do devedor. Aditiva: nenhuma linha existente muda.
     */
    public function up(): void
    {
        Schema::table('ex_group_member_responsibles', function (Blueprint $table) {
            $table->unsignedBigInteger('created_by')->nullable()->after('responsible_user_id');
            // Nome gerado: `ex_group_member_responsibles_created_by_foreign`, 47 caracteres (limite 64).
            $table->foreign('created_by')->references('id')->on('ex_users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('ex_group_member_responsibles', function (Blueprint $table) {
            $table->dropForeign(['created_by']);
            $table->dropColumn('created_by');
        });
    }
};
