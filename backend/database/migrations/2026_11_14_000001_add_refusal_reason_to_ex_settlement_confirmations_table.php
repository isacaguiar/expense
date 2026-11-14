<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Motivo opcional da recusa (feature 20261114-historico-de-comprovantes-e-justificativa, plan §1,
     * D4-A): o comprovante recusado continua na linha atual até ser substituído ou retirado (R6), então
     * o motivo mora aqui; só vai para a tabela de entradas quando o comprovante sai de cena. Nulo = sem
     * motivo, o que já vale para todas as linhas de hoje: nenhuma linha existente é tocada.
     */
    public function up(): void
    {
        Schema::table('ex_settlement_confirmations', function (Blueprint $table) {
            $table->string('refusal_reason', 500)->nullable()->after('refused_at');
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
            $table->dropColumn('refusal_reason');
        });
    }
};
