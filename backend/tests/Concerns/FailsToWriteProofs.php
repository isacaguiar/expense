<?php

namespace Tests\Concerns;

use Illuminate\Support\Facades\Storage;

/**
 * Simula o disco privado `local` que não consegue gravar (disco cheio,
 * permissão). O disco `local` do projeto tem `'throw' => false`, então
 * `putFileAs` devolve `false` em vez de lançar exceção — é esse o cenário que
 * `ProofStorage::store` precisa recusar (achado B3 da revisão de segurança da
 * feature 20261022-editar-comprovante-depois-de-paga).
 */
trait FailsToWriteProofs
{
    /**
     * Mantém o disco falso funcionando para tudo (leitura, `allFiles`, `put`),
     * exceto `putFileAs`, que passa a devolver `false`.
     */
    protected function failProofWrites(): void
    {
        $real = Storage::fake('local');

        $failing = \Mockery::mock($real);
        $failing->shouldReceive('putFileAs')->andReturn(false);

        Storage::set('local', $failing);
    }
}
