<?php

namespace App\Exceptions;

use DomainException;

/**
 * O credor (ou quem enviou) não pode aceitar, recusar ou excluir o comprovante nesse estado: ciclo ainda
 * não fechado ou já encerrado, comprovante que não passa pelo aceite ou que já foi decidido (feature
 * 20261110-quem-paga-se-declara-e-credor-recusa, plan §4). A mensagem vai ao usuário e o controller a
 * devolve como `422 { error }`, o formato das rotas de acerto.
 */
class SettlementDecisionRefused extends DomainException {}
