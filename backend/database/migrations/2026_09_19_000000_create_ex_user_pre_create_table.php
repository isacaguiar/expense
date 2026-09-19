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
        Schema::create('ex_user_pre_create', function (Blueprint $table) {
            $table->id();

            $table->string('name');

            // Único para que um reenvio do formulário atualize a linha pendente
            // (updateOrCreate) em vez de acumular pré-cadastros do mesmo e-mail.
            $table->string('email')->unique();

            // Mesmo formato de ex_users.whatsapp — o telefone do cadastro é
            // copiado para lá quando o User nasce.
            $table->string('whatsapp')->nullable();

            // Já hasheada no pré-cadastro: senha em claro nunca persiste.
            $table->string('password');

            // Hash do código de 6 dígitos — o código em claro só existe no
            // corpo do e-mail enviado.
            $table->string('code_hash');

            // Segredo opaco devolvido a quem submeteu o formulario, exigido de
            // volta em verify/resend. Sem ele, um terceiro poderia sobrescrever
            // o pre-cadastro pendente de um e-mail alheio (updateOrCreate e
            // chaveado so por e-mail) e a vitima, ao digitar o codigo que
            // chegou na caixa dela, criaria a conta com a senha do atacante.
            $table->string('handle_hash');

            $table->unsignedTinyInteger('attempts')->default(0);
            $table->unsignedTinyInteger('resend_count')->default(0);
            $table->timestamp('expires_at');
            $table->timestamp('last_sent_at')->nullable();

            // Linha usada é marcada, não apagada: hard delete é gate humano
            // (docs/sdd/00-constitution.md §5.2).
            $table->timestamp('consumed_at')->nullable();

            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ex_user_pre_create');
    }
};
