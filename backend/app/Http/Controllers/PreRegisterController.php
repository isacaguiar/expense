<?php

namespace App\Http\Controllers;

use App\Http\Requests\PreRegisterRequest;
use App\Http\Requests\PreRegisterResendRequest;
use App\Http\Requests\PreRegisterVerifyRequest;
use App\Services\PreRegistrationService;
use Illuminate\Http\JsonResponse;

/**
 * Auto-cadastro público: formulário -> código por e-mail -> conta criada e já
 * autenticada. Rotas fora do grupo `jwt.auth` por necessidade (quem chama
 * ainda não tem conta), mas nenhuma resposta devolve dado pessoal — só
 * mensagem e contadores, para as rotas não virarem um leitor de pré-cadastros
 * alheios (docs/sdd/00-constitution.md §6.5).
 *
 * Ver docs/feature/concluidas/202609/20260919-cadastro-de-usuarios/plan.md §4.
 */
class PreRegisterController extends Controller
{
    public function __construct(private readonly PreRegistrationService $preRegistration) {}

    /** Recebe o formulário, grava o pré-cadastro e dispara o código. */
    public function store(PreRegisterRequest $request): JsonResponse
    {
        $handle = $this->preRegistration->start($request->validated());

        return response()->json([
            'message' => 'Enviamos um código de confirmação para o seu e-mail.',
            // Segredo opaco que amarra a confirmação a quem submeteu este
            // formulário -- exigido de volta em verify/resend.
            'handle' => $handle,
            'expires_in_seconds' => PreRegistrationService::CODE_TTL_MINUTES * 60,
            'resend_available_in' => PreRegistrationService::RESEND_COOLDOWN_SECONDS,
        ]);
    }

    /** Confere o código e devolve o mesmo payload de POST /login. */
    public function verify(PreRegisterVerifyRequest $request): JsonResponse
    {
        $user = $this->preRegistration->confirm(
            $request->input('email'),
            $request->input('handle'),
            $request->input('code')
        );

        $token = auth('api')->login($user);

        return response()->json([
            'access_token' => $token,
            'token_type' => 'bearer',
            'expires_in' => auth('api')->factory()->getTTL() * 60,
        ], 201);
    }

    /** Gera e reenvia um código novo para um pré-cadastro pendente. */
    public function resend(PreRegisterResendRequest $request): JsonResponse
    {
        $this->preRegistration->resend(
            $request->input('email'),
            $request->input('handle')
        );

        return response()->json([
            'message' => 'Enviamos um novo código para o seu e-mail.',
            'expires_in_seconds' => PreRegistrationService::CODE_TTL_MINUTES * 60,
            'resend_available_in' => PreRegistrationService::RESEND_COOLDOWN_SECONDS,
        ]);
    }
}
