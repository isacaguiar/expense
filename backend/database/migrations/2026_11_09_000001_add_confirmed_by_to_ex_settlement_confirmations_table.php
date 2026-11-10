<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Quem enviou o comprovante do acerto (feature 20261109-responsavel-do-devedor, plan §1, R7):
     * o acerto segue sendo do devedor (`from_user_id`), e esta coluna guarda o responsável que pagou
     * por ele. Nula = o próprio devedor, o que já vale para todas as linhas de hoje, então nenhuma
     * linha existente é tocada. `nullOnDelete`: se a conta de quem enviou for apagada, a confirmação
     * fica e o campo vira nulo.
     */
    public function up(): void
    {
        Schema::table('ex_settlement_confirmations', function (Blueprint $table) {
            $table->unsignedBigInteger('confirmed_by')->nullable()->after('to_user_id');
            $table->foreign('confirmed_by')->references('id')->on('ex_users')->nullOnDelete();
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
            $table->dropForeign(['confirmed_by']);
            $table->dropColumn('confirmed_by');
        });
    }
};
