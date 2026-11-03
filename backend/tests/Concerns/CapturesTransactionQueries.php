<?php

namespace Tests\Concerns;

use Illuminate\Database\Events\TransactionBeginning;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/**
 * Lê, pelo SQL, o que uma transação faz primeiro. O PHPUnit usa uma conexão só e não reproduz
 * requisições simultâneas, então a ordem dos locks e o ponto em que a leitura consistente
 * (o snapshot do REPEATABLE READ) abre só se provam pela sequência das consultas.
 */
trait CapturesTransactionQueries
{
    /**
     * Roda `$action` e devolve as consultas executadas **depois** que a primeira transação abriu
     * (o `BEGIN`, ou o `SAVEPOINT` dentro do `DatabaseTransactions` do teste, dispara o mesmo
     * evento). O que veio antes dela fica de fora.
     *
     * @return list<string>
     */
    protected function queriesAfterTheTransactionOpens(callable $action): array
    {
        $events = [];
        Event::listen(TransactionBeginning::class, function () use (&$events) {
            $events[] = 'BEGIN';
        });
        DB::listen(function ($query) use (&$events) {
            $events[] = $query->sql;
        });

        $action();

        $position = array_search('BEGIN', $events, true);
        $this->assertNotFalse($position, 'nenhuma transação foi aberta');

        return array_slice($events, $position + 1);
    }
}
