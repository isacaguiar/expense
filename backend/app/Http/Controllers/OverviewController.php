<?php

namespace App\Http\Controllers;

use App\Services\PersonalOverviewService;

/**
 * `GET /api/me/overview`: a visão pessoal por grupo (o que o usuário logado
 * deve, tem a receber e os itens dele), no ciclo em foco de cada grupo. O
 * cálculo mora em `PersonalOverviewService`; o escopo (só grupos em que o
 * usuário é membro) é a própria consulta do serviço, não um filtro posterior.
 * Só lê: não sela ciclo nem notifica. Ver
 * docs/feature/20261010-painel-financeiro-pessoal/plan.md §1.
 */
class OverviewController extends Controller
{
    public function __construct(private readonly PersonalOverviewService $overview) {}

    public function show()
    {
        return response()->json($this->overview->forUser(auth('api')->user()));
    }
}
