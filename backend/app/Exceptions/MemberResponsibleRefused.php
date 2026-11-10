<?php

namespace App\Exceptions;

use DomainException;

/**
 * A lista de responsáveis de um devedor foi recusada por regra de negócio (o próprio devedor na
 * lista, id repetido, quem não é membro do grupo). A mensagem vai ao usuário e o controller a
 * devolve como `422`, sem ter alterado nada. Ver
 * docs/feature/concluidas/202611/20261109-responsavel-do-devedor/specify.md R3.
 */
class MemberResponsibleRefused extends DomainException {}
