<?php

namespace App\Exceptions;

use DomainException;

/**
 * O desfazer do pagamento de uma compra foi recusado por regra de negócio (tipo de
 * despesa, nada a desfazer, ciclo já encerrado). A mensagem vai ao usuário e o
 * controller a devolve como `422`. Ver
 * docs/feature/concluidas/202610/20261027-pagamento-no-cadastro-despesa/specify.md R19.
 */
class PurchaseUndoRefused extends DomainException {}
