<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     *
     * Histórico dos comprovantes do acerto (feature 20261114-historico-de-comprovantes-e-justificativa,
     * plan §1, D2-A): uma linha por comprovante que **saiu de cena** (substituído ou retirado) e por
     * complemento com justificativa, com o arquivo guardado. A linha de `ex_settlement_confirmations`
     * segue sendo o comprovante atual do par; esta tabela fica fora do caminho do selo (R3) e é **só de
     * acréscimo** (R1): não há `updated_at`, e o model lança exceção ao editar ou apagar uma entrada.
     *
     * Todas as colunas de data são **nulas**, de propósito: com `explicit_defaults_for_timestamp`
     * desligado (o que pode ser o caso em produção), a primeira coluna `timestamp NOT NULL` ganha
     * `ON UPDATE CURRENT_TIMESTAMP`, a armadilha do backlog 147. `sent_by` é `nullOnDelete`: se a conta de
     * quem enviou for apagada, a entrada fica e o campo vira nulo. O índice tem nome explícito (o gerado
     * passaria de 64 caracteres).
     */
    public function up(): void
    {
        Schema::create('ex_settlement_proof_entries', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('group_id');
            $table->foreign('group_id')->references('id')->on('ex_groups')->onDelete('cascade');

            $table->date('cycle_start');

            // O par do acerto, como em `ex_settlement_confirmations`: o devedor e o credor.
            $table->unsignedBigInteger('from_user_id');
            $table->foreign('from_user_id')->references('id')->on('ex_users')->onDelete('cascade');

            $table->unsignedBigInteger('to_user_id');
            $table->foreign('to_user_id')->references('id')->on('ex_users')->onDelete('cascade');

            // `replaced`, `withdrawn` ou `complement`.
            $table->string('kind', 20);

            // Quem enviou o comprovante desta entrada (ou o complemento).
            $table->unsignedBigInteger('sent_by')->nullable();
            $table->foreign('sent_by')->references('id')->on('ex_users')->nullOnDelete();

            // Quem **agiu** para criar a entrada (o autor do reenvio, quem retirou, o autor do complemento):
            // é por ele que o teto é dividido (D14). Não é `sent_by`, que é quem enviou o comprovante que saiu.
            $table->unsignedBigInteger('acted_by')->nullable();
            $table->foreign('acted_by')->references('id')->on('ex_users')->nullOnDelete();

            // O estado em que o comprovante estava ao sair de cena; nulo no complemento.
            $table->string('status_at_exit', 20)->nullable();

            $table->decimal('amount', 10, 2);
            $table->string('proof_path');

            // A nota do comprovante e, no complemento, a justificativa.
            $table->string('note', 500)->nullable();
            $table->string('refusal_reason', 500)->nullable();

            $table->timestamp('sent_at')->nullable();
            $table->timestamp('refused_at')->nullable();
            $table->timestamp('created_at')->nullable();

            // Contagem do teto e leitura do histórico de um par num ciclo.
            $table->index(['group_id', 'cycle_start', 'from_user_id', 'to_user_id'], 'settlement_proof_entries_pair_index');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('ex_settlement_proof_entries');
    }
};
