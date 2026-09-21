<?php

namespace App\Exceptions;

use Exception;
use Illuminate\Http\JsonResponse;

/**
 * Pedido de código recusado por ritmo: ou o cooldown de reenvio ainda não
 * passou, ou as tentativas daquele código se esgotaram. Carrega quantos
 * segundos faltam para um novo pedido valer, para a tela poder mostrar o
 * contador sem depender de header (que o CORS não expõe).
 *
 * Ver docs/feature/concluidas/202609/20260919-cadastro-de-usuarios/plan.md §4.
 */
class PreRegistrationThrottled extends Exception
{
    public function __construct(string $message, public readonly int $retryAfterSeconds)
    {
        parent::__construct($message);
    }

    public function render(): JsonResponse
    {
        return response()->json([
            'message' => $this->getMessage(),
            'retry_after' => $this->retryAfterSeconds,
        ], 429);
    }
}
