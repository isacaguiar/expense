<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Convite pendente para entrar num grupo (feature 20261103-convite-pendente-no-grupo,
     * plan §1). Fica fora do pivô `ex_groups_members` de propósito: quem tem convite não é
     * membro, e `authorizeMembership`/`whereHas('members')` não podem enxergá-lo.
     */
    public function up(): void
    {
        Schema::create('ex_group_invitations', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('group_id');
            $table->foreign('group_id')->references('id')->on('ex_groups')->onDelete('cascade');

            // O convidado. A conta existe sempre (o `store()` cria o `User` quando o e-mail é novo).
            $table->unsignedBigInteger('user_id');
            $table->foreign('user_id')->references('id')->on('ex_users')->onDelete('cascade');

            // Quem convidou. Nulo se a conta dele deixar de existir: o convite sobrevive.
            $table->unsignedBigInteger('invited_by')->nullable();
            $table->foreign('invited_by')->references('id')->on('ex_users')->nullOnDelete();

            // pending | accepted | declined | cancelled. `string`, e não `enum`: um estado novo
            // seria alterar o tipo de uma coluna existente (Constitution §4, item 2).
            $table->string('status', 16)->default('pending');

            // `dateTime`, e não `timestamp`: em MySQL com `explicit_defaults_for_timestamp`
            // desligado a primeira coluna `timestamp NOT NULL` ganha `ON UPDATE
            // CURRENT_TIMESTAMP` e a validade seria sobrescrita a cada UPDATE.
            $table->dateTime('expires_at');
            $table->dateTime('notified_at')->nullable();
            $table->dateTime('responded_at')->nullable();

            $table->timestamps();

            // Um convite por (grupo, pessoa): convidar de novo reaproveita a linha.
            $table->unique(['group_id', 'user_id']);
            // Lista de convites recebidos de um usuário.
            $table->index(['user_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ex_group_invitations');
    }
};
