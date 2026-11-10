<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * "Quem paga por quem" num grupo (feature 20261109-responsavel-do-devedor, plan §1): cada
     * devedor tem, em cada grupo, nenhum, um ou vários responsáveis, que podem enviar o
     * comprovante dos acertos dele. Fica fora do pivô `ex_groups_members` de propósito:
     * `authorizeMembership` e `whereHas('members')` não podem enxergar o vínculo.
     */
    public function up(): void
    {
        Schema::create('ex_group_member_responsibles', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('group_id');
            $table->foreign('group_id')->references('id')->on('ex_groups')->onDelete('cascade');

            // O devedor.
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('ex_users')->onDelete('cascade');

            // Quem paga por ele.
            $table->unsignedBigInteger('responsible_user_id');
            $table->foreign('responsible_user_id')->references('id')->on('ex_users')->onDelete('cascade');

            $table->timestamps();

            // Nome explícito nos dois índices: o gerado pelo Laravel para o primeiro
            // (`ex_group_member_responsibles_group_id_user_id_responsible_user_id_unique`)
            // tem 72 caracteres e o MySQL aceita 64.
            $table->unique(['group_id', 'user_id', 'responsible_user_id'], 'member_responsible_unique');
            // "Por quem este usuário paga", usado na limpeza da saída de membro.
            $table->index(['group_id', 'responsible_user_id'], 'member_responsible_by_responsible_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ex_group_member_responsibles');
    }
};
