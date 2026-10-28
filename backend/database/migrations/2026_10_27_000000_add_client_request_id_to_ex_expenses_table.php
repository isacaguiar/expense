<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Idempotência do cadastro de despesa (docs/feature/20261027-pagamento-no-cadastro-despesa,
 * plan.md §2): `client_request_id` é a chave que o cliente gera por formulário e
 * `request_fingerprint` o sha-256 do corpo normalizado, para o servidor distinguir
 * um reenvio idêntico (devolve a despesa que já existe) de um reuso da chave com
 * outros dados (409). Aditiva: as duas colunas são nullable, e o MySQL aceita
 * vários NULL num índice único.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ex_expenses', function (Blueprint $table) {
            $table->char('client_request_id', 36)->nullable();
            $table->char('request_fingerprint', 64)->nullable();
            $table->unique(['user_creator_id', 'client_request_id'], 'ex_expenses_creator_request_unique');
        });
    }

    public function down(): void
    {
        // Ao criar o único composto, o MySQL passa a usá-lo como índice da FK de
        // `user_creator_id` e descarta o índice simples que a sustentava; sem
        // recriá-lo antes, o `dropUnique` falha com o erro 1553. O nome é o que a
        // FK gera sozinha na criação da tabela, para o schema voltar idêntico.
        Schema::table('ex_expenses', function (Blueprint $table) {
            $table->index('user_creator_id', 'ex_expenses_user_creator_id_foreign');
        });

        Schema::table('ex_expenses', function (Blueprint $table) {
            $table->dropUnique('ex_expenses_creator_request_unique');
            $table->dropColumn(['client_request_id', 'request_fingerprint']);
        });
    }
};
