<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Aceite e recusa do comprovante pelo credor (feature 20261110-quem-paga-se-declara-e-credor-recusa,
     * plan §1, D4-B): só o credor do par decide, então a data basta (quem decidiu é sempre `to_user_id`).
     * O estado do comprovante é **derivado** destas duas datas e de `confirmed_by`, sem coluna `status`.
     * Nulas = sem decisão, o que já vale para todas as linhas de hoje: nenhuma linha existente é tocada.
     */
    public function up(): void
    {
        Schema::table('ex_settlement_confirmations', function (Blueprint $table) {
            $table->timestamp('accepted_at')->nullable()->after('confirmed_at');
            $table->timestamp('refused_at')->nullable()->after('accepted_at');
        });
    }

    /**
     * Reverse the migrations.
     *
     * Desfaz só o que esta migration criou.
     */
    public function down(): void
    {
        Schema::table('ex_settlement_confirmations', function (Blueprint $table) {
            $table->dropColumn(['accepted_at', 'refused_at']);
        });
    }
};
