<?php

namespace App\Http\Controllers;

use App\Exceptions\PurchaseUndoRefused;
use App\Models\Expense;
use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\Quota;
use App\Models\SettlementConfirmation;
use App\Services\CycleSummaryService;
use App\Services\ExpensePaymentService;
use App\Support\BillingCycle;
use App\Support\ClosingBreakdown;
use App\Support\ExpenseRequestFingerprint;
use App\Support\InstallmentSchedule;
use App\Support\Notifier;
use App\Support\ProofStorage;
use App\Support\WhatsApp\WhatsAppNotifier;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class ExpenseController extends Controller
{
    public function indexByGroup($groupId, Request $request)
    {
        $group = Group::findOrFail($groupId);
        $this->authorizeGroupMembership($group);

        $data = $request->validate([
            'year' => 'required|integer',
            'month' => 'required|integer|between:1,12',
        ]);

        $monthStart = Carbon::create($data['year'], $data['month'], 1);

        $mapRow = function (Expense $expense, ?Carbon $projectedDate = null) {
            return [
                'id' => $expense->id,
                'description' => $expense->description,
                'date' => ($projectedDate ?? $expense->date_payment)->toDateString(),
                'value' => $expense->total_value,
                'payerName' => $expense->payers->pluck('name')->implode(', '),
                'isFixed' => $expense->expense_type === 'FIXED',
            ];
        };

        // Despesas cujo date_payment cai neste mês (à vista, parcelas, e a criação da Fixa).
        // Fixa também respeita o corte de recorrência aqui: se o corte é o próprio mês de
        // criação (ou anterior), a despesa não deve aparecer nem nesse mês.
        $direct = Expense::where('group_id', $groupId)
            ->where('deleted', false)
            ->where('expense_type', '!=', 'IN_INSTALLMENTS')
            ->whereYear('date_payment', $data['year'])
            ->whereMonth('date_payment', $data['month'])
            ->where(function ($query) use ($monthStart) {
                $query->where('expense_type', '!=', 'FIXED')
                    ->orWhereNull('fixed_recurrence_ends_at')
                    ->orWhere('fixed_recurrence_ends_at', '>', $monthStart);
            })
            ->with('payers')
            ->get();

        // Despesas Fixa criadas em mês anterior, ainda ativas neste mês (projeção virtual).
        $projectedFixed = Expense::where('group_id', $groupId)
            ->where('deleted', false)
            ->where('expense_type', 'FIXED')
            ->where('date_payment', '<', $monthStart)
            ->where(function ($query) use ($monthStart) {
                $query->whereNull('fixed_recurrence_ends_at')
                    ->orWhere('fixed_recurrence_ends_at', '>', $monthStart);
            })
            ->with('payers')
            ->get();

        // Parcelas de despesas IN_INSTALLMENTS cujo vencimento (date_expected) cai neste
        // mês — inclui o mês de criação (1ª parcela) e os seguintes, ao contrário de
        // $direct, que só olha o date_payment da despesa (mês de criação).
        $installmentQuotas = Quota::whereYear('date_expected', $data['year'])
            ->whereMonth('date_expected', $data['month'])
            ->whereHas('expense', function ($query) use ($groupId) {
                $query->where('group_id', $groupId)
                    ->where('deleted', false)
                    ->where('expense_type', 'IN_INSTALLMENTS');
            })
            ->with('expense.payers')
            ->get();

        $mapQuotaRow = fn (Quota $quota) => [
            'id' => $quota->expense->id,
            'description' => $quota->expense->description,
            'date' => $quota->date_expected->toDateString(),
            'value' => $quota->value_quota,
            'payerName' => $quota->expense->payers->pluck('name')->implode(', '),
            'isFixed' => false,
        ];

        $expenses = $direct->map(fn (Expense $expense) => $mapRow($expense))
            ->concat($projectedFixed->map(function (Expense $expense) use ($monthStart, $mapRow) {
                $day = min($expense->date_payment->day, $monthStart->daysInMonth);

                return $mapRow($expense, $monthStart->copy()->day($day));
            }))
            ->concat($installmentQuotas->map($mapQuotaRow))
            ->sortBy('date')
            ->values();

        return response()->json($expenses);
    }

    public function show($id)
    {
        $expense = $this->findExpenseForMember($id);
        $expense->load(['payers', 'quotas']);
        $this->hydrateQuotaExpense($expense);
        $this->appendValuePerPerson($expense);

        return response()->json($expense);
    }

    /**
     * Aponta a relação `expense` de cada Quota já carregada de volta para o
     * próprio `$expense` — o accessor `payment_proof_url` precisa de
     * `expense->group_id` para montar a URL assinada, e sem isso cada Quota
     * dispararia um SELECT (N+1).
     */
    private function hydrateQuotaExpense(Expense $expense): void
    {
        if ($expense->relationLoaded('quotas')) {
            $expense->quotas->each(fn (Quota $quota) => $quota->setRelation('expense', $expense));
        }
    }

    /**
     * Acrescenta `value_per_person` a cada Quota já carregada: a mesma conta do
     * `valuePerPerson` de CycleSummaryService::compute() — round(valor ÷ max(pagadores,
     * 1), 2) — para o cliente não repetir a regra. Atributo local do `show()`
     * (e não acessor de Quota) porque aparece só nesta resposta; usa só as
     * relações em memória, então não faz consulta por quota.
     */
    private function appendValuePerPerson(Expense $expense): void
    {
        if (! $expense->relationLoaded('quotas') || ! $expense->relationLoaded('payers')) {
            return;
        }

        $divisor = max($expense->payers->count(), 1);

        $expense->quotas->each(fn (Quota $quota) => $quota->setAttribute(
            'value_per_person',
            round((float) $quota->value_quota / $divisor, 2)
        ));
    }

    public function update(Request $request, $id)
    {
        $expense = $this->findExpenseForMember($id);
        $this->authorizeExpenseOwner($expense);

        if ($response = $this->rejectIfCycleClosed($expense)) {
            return $response;
        }

        $data = $request->validate([
            'description' => 'sometimes|required|string|max:255',
            'date_payment' => 'sometimes|required|date',
            'total_value' => 'sometimes|required|numeric|min:0',
            'user_payer_id' => ['sometimes', 'required', Rule::exists('ex_groups_members', 'user_id')->where('group_id', $expense->group_id)],
            'payers' => 'sometimes|required|array|min:1',
            'payers.*' => Rule::exists('ex_groups_members', 'user_id')->where('group_id', $expense->group_id),
            // expense_type/installments/quotas: edição de tipo (só À Vista <->
            // Parcelada — Fixa fica de fora dos dois lados, ver abaixo).
            // docs/feature/concluidas/202608/20260826-editar-tipo-despesa/plan.md §1.
            'expense_type' => 'sometimes|required|in:IN_CASH,IN_INSTALLMENTS',
            // Teto de parcelas só quando o servidor gera as quotas (sem `quotas`
            // no payload), como no store().
            'installments' => ['sometimes', 'required', 'integer', 'min:2', Rule::when(! $request->has('quotas'), ['max:'.InstallmentSchedule::MAX_INSTALLMENTS])],
            'quotas' => 'sometimes|required|array|min:1',
            'quotas.*.date_expected' => 'required_with:quotas|date',
            'quotas.*.number' => 'required_with:quotas|integer',
            'quotas.*.value_quota' => 'required_with:quotas|numeric|min:0',
        ]);

        $changingType = array_key_exists('expense_type', $data);

        // Transição de/para FIXED não é suportada por esta feature — é um
        // compromisso recorrente (materializa quota novo mês a mês), converter
        // de/pra ela é uma decisão maior que fica pra outro pedido. A regra
        // `in:IN_CASH,IN_INSTALLMENTS` já recusa 'FIXED' como alvo; falta só
        // recusar a origem.
        if ($expense->expense_type === 'FIXED' && $changingType) {
            return response()->json(['error' => 'Não é possível mudar o tipo de uma despesa fixa.'], 422);
        }

        $anyQuotaPaid = false;
        $newQuotas = null;

        // FIXED fica de fora: seu total_value é o valor do template pra
        // ocorrências futuras/ainda não materializadas — uma ocorrência já
        // paga já tem Quota própria congelada (materializeFixedOccurrenceQuota)
        // e não é afetada por essa edição, então não há o que proteger aqui.
        if ($expense->expense_type !== 'FIXED') {
            $anyQuotaPaid = $expense->quotas()->where('paid', true)->exists();

            // Regra pedida explicitamente pelo usuário: despesa parcelada com
            // qualquer parcela paga (na prática, sempre a 1ª primeiro) trava a
            // edição inteira — não só tipo/valor. Mesmo precedente de
            // destroy() (bloqueia a ação inteira, não um campo).
            if ($expense->expense_type === 'IN_INSTALLMENTS' && $anyQuotaPaid) {
                return response()->json(['error' => 'Não é possível editar uma despesa parcelada depois que a primeira parcela foi paga.'], 422);
            }

            if ($anyQuotaPaid && (array_key_exists('total_value', $data) || $changingType)) {
                return response()->json(['error' => 'Não é possível alterar o valor ou o tipo de uma despesa já paga.'], 422);
            }
        }

        if ($changingType) {
            $finalTotalValue = round((float) ($data['total_value'] ?? $expense->total_value), 2);

            if ($data['expense_type'] === 'IN_INSTALLMENTS') {
                $hasQuotas = array_key_exists('quotas', $data);

                if (! array_key_exists('installments', $data)) {
                    return response()->json(['error' => $hasQuotas
                        ? 'Informe installments e quotas para parcelar a despesa.'
                        : 'Informe installments para parcelar a despesa.'], 422);
                }

                if ($hasQuotas) {
                    if (count($data['quotas']) !== (int) $data['installments']) {
                        return response()->json(['error' => 'A quantidade de quotas deve ser igual a installments.'], 422);
                    }

                    $quotasSum = round(array_sum(array_column($data['quotas'], 'value_quota')), 2);
                    if (abs($quotasSum - $finalTotalValue) > 0.01) {
                        return response()->json(['error' => 'A soma das quotas deve ser igual a total_value.'], 422);
                    }

                    $newQuotas = $data['quotas'];
                } else {
                    // Sem `quotas`, o servidor rateia o total final na data final.
                    $newQuotas = $this->generateQuotas(
                        $finalTotalValue,
                        (int) $data['installments'],
                        $data['date_payment'] ?? $expense->date_payment->toDateString()
                    );
                }
            } else {
                $data['installments'] = 1;
                $newQuotas = [[
                    'number' => 1,
                    'date_expected' => $data['date_payment'] ?? $expense->date_payment->toDateString(),
                    'value_quota' => $finalTotalValue,
                ]];
            }
        } elseif ($expense->expense_type !== 'FIXED' && ! $anyQuotaPaid) {
            // Sem troca de tipo, as quotas só são refeitas se valor, data ou nº
            // de parcelas mudaram; a decisão e o rateio ficam no helper.
            $newQuotas = $this->regeneratedQuotas($expense, $data);
        }

        // Uma transação só: apagar e recriar as quotas é a parte que pode falhar no
        // meio, e a despesa não pode ficar com a edição aplicada e as quotas
        // parciais (mesmo padrão do store()).
        DB::transaction(function () use ($expense, $data, $newQuotas) {
            $expense->update(Arr::except($data, ['payers', 'quotas']));

            if (array_key_exists('payers', $data)) {
                $expense->payers()->sync($data['payers']);
            }

            if ($newQuotas !== null) {
                // Seguro: só alcançado depois de confirmar acima que nenhuma quota
                // está paga. ex_quotas não tem coluna de soft delete — são linhas
                // geradas a partir de expense_type/installments/total_value, não
                // uma entidade de negócio própria (Constitution §1.5 é sobre
                // grupo/despesa).
                $expense->quotas()->delete();

                foreach ($newQuotas as $quota) {
                    $expense->quotas()->create([
                        'date_expected' => $quota['date_expected'],
                        'number' => $quota['number'],
                        'paid' => false,
                        'value_quota' => $quota['value_quota'],
                    ]);
                }
            }
        });

        $fresh = $expense->fresh(['payers', 'quotas']);
        $this->hydrateQuotaExpense($fresh);

        return response()->json($fresh);
    }

    /**
     * Rateio gerado pelo servidor (InstallmentSchedule::build). Recusa com 422 o que
     * passaria do ano 9999: a coluna `date` não comporta, e o MySQL gravaria datas
     * erradas em silêncio em vez de falhar.
     *
     * @return list<array{number: int, date_expected: string, value_quota: float}>
     */
    private function generateQuotas(float|int|string $total, int $installments, string $startDate): array
    {
        if (! InstallmentSchedule::fits($startDate, $installments)) {
            throw ValidationException::withMessages([
                'date_payment' => 'A última parcela passaria do ano '.InstallmentSchedule::MAX_YEAR.'. Use uma data de pagamento anterior ou menos parcelas.',
            ]);
        }

        return InstallmentSchedule::build($total, $installments, $startDate);
    }

    /**
     * Quotas que o servidor refaz quando valor, data ou nº de parcelas de uma
     * despesa não-fixa e sem parcela paga mudam sem troca de tipo; `null` se
     * nada precisa ser refeito. Reenviar os valores já gravados não conta como
     * mudança — evita recriar as quotas (e trocar seus IDs) à toa. À Vista tem
     * uma quota; Parcelada é rateada em InstallmentSchedule com o total, o nº de
     * parcelas e a data finais (payload ou o que já está gravado).
     *
     * @return list<array{number: int, date_expected: string, value_quota: float}>|null
     */
    private function regeneratedQuotas(Expense $expense, array $data): ?array
    {
        $cents = fn ($value) => (int) round(round((float) $value, 2) * 100);
        $isInstallments = $expense->expense_type === 'IN_INSTALLMENTS';

        $changed = (array_key_exists('total_value', $data)
                && $cents($data['total_value']) !== $cents($expense->total_value))
            || (array_key_exists('date_payment', $data)
                && Carbon::parse($data['date_payment'])->toDateString() !== $expense->date_payment->toDateString())
            || ($isInstallments
                && array_key_exists('installments', $data)
                && (int) $data['installments'] !== (int) $expense->installments);

        if (! $changed) {
            return null;
        }

        $installments = $isInstallments ? (int) ($data['installments'] ?? $expense->installments) : 1;

        // O teto de campo de `installments` só vê o payload; uma despesa legada
        // acima do teto, com valor ou data editados, cai aqui.
        if ($installments > InstallmentSchedule::MAX_INSTALLMENTS) {
            throw ValidationException::withMessages([
                'installments' => 'O número de parcelas não pode ser maior que '.InstallmentSchedule::MAX_INSTALLMENTS.'.',
            ]);
        }

        return $this->generateQuotas(
            $data['total_value'] ?? $expense->total_value,
            $installments,
            Carbon::parse($data['date_payment'] ?? $expense->date_payment)->toDateString()
        );
    }

    public function destroy($id)
    {
        $expense = $this->findExpenseForMember($id);
        $this->authorizeExpenseOwner($expense);

        if ($response = $this->rejectIfCycleClosed($expense)) {
            return $response;
        }

        // Ao contrário de `update()`, aqui não há bypass de FIXED: excluir
        // apaga a definição inteira (todas as ocorrências, inclusive as já
        // pagas), não só o template pra frente — se qualquer ocorrência está
        // paga, é preciso desfazer o pagamento antes.
        if ($expense->quotas()->where('paid', true)->exists()) {
            return response()->json(['error' => 'Não é possível excluir uma despesa já paga. Desfaça o pagamento primeiro.'], 422);
        }

        $expense->update(['deleted' => true]);

        return response()->json(['message' => 'Despesa marcada como deletada.']);
    }

    /**
     * Competência passada é só leitura: `IN_CASH`/`IN_INSTALLMENTS` cujo
     * `date_payment` cai numa competência já fechada não podem mais ser
     * editados/apagados. `FIXED` fica de fora dessa checagem — é uma definição
     * recorrente, não presa a uma única competência; a materialização da
     * `Quota` de cada mês (`materializeFixedOccurrenceQuota`) já garante que
     * editar o valor depois não muda competências já congeladas.
     */
    private function rejectIfCycleClosed(Expense $expense): ?\Illuminate\Http\JsonResponse
    {
        if ($expense->expense_type === 'FIXED') {
            return null;
        }

        return $this->rejectIfCompetenceClosed($expense->group, $expense->date_payment);
    }

    /**
     * Retorna uma resposta 422 se a competência (mês de fatura, `BillingCycle`)
     * que contém `$referenceDate` está fechada — automaticamente (por data,
     * definitivo) ou manualmente (`GroupCycleSnapshot::closed_manually_at`
     * ainda ativo, revisável até a virada do mês). `null` se estiver aberta.
     * Único ponto de checagem de competência fechada, reaproveitado por toda
     * ação nova (fechar, reabrir, pagar, despagar, criar).
     */
    private function rejectIfCompetenceClosed(Group $group, Carbon $referenceDate): ?\Illuminate\Http\JsonResponse
    {
        $closedResponse = response()->json(['error' => 'Não é possível alterar dados de uma competência já fechada.'], 422);

        if (BillingCycle::statusFor($group->closing_day, $referenceDate, Carbon::now()) === 'closed') {
            return $closedResponse;
        }

        $cycleStart = BillingCycle::cycleFor($group->closing_day, $referenceDate)['start'];

        $snapshot = GroupCycleSnapshot::where('group_id', $group->id)
            ->where('cycle_start', $cycleStart->toDateString())
            ->first();

        if ($snapshot && $snapshot->isManuallyClosedAndActive()) {
            return $closedResponse;
        }

        return null;
    }

    public function store(Request $request)
    {
        $request->validate([
            'group_id' => 'required|exists:ex_groups,id',
        ]);

        $group = Group::findOrFail($request->group_id);
        $this->authorizeGroupMembership($group);
        abort_if($group->deleted, 404);

        $request->validate([
            'date_payment' => 'required|date',
            'description' => 'required|string|max:255',
            'expense_type' => 'required|in:IN_CASH,IN_INSTALLMENTS,FIXED',
            'installments' => [
                'required',
                'integer',
                'min:1',
                // Sem `quotas`, é o servidor quem gera as parcelas: entre 2 e o teto, para
                // que um payload pequeno não peça linhas sem limite. Com `quotas` enviadas,
                // nada muda (docs/feature/concluidas/202610/20261008-rateio-parcelas-no-backend/specify.md R7).
                Rule::when(
                    ! $request->has('quotas') && $request->expense_type === 'IN_INSTALLMENTS',
                    ['between:2,'.InstallmentSchedule::MAX_INSTALLMENTS]
                ),
            ],
            'total_value' => 'required|numeric|min:0',
            'user_creator_id' => 'required|exists:ex_users,id',
            'user_payer_id' => ['required', Rule::exists('ex_groups_members', 'user_id')->where('group_id', $request->group_id)],
            'payers' => 'required|array|min:1',
            'payers.*' => Rule::exists('ex_groups_members', 'user_id')->where('group_id', $request->group_id),
            'quotas' => 'sometimes|array|min:1',
            'quotas.*.date_expected' => 'required|date',
            'quotas.*.number' => 'required|integer',
            'quotas.*.value_quota' => 'required|numeric|min:0',
            // Pagamento registrado junto do cadastro (feature
            // 20261027-pagamento-no-cadastro-despesa): foto e descrição são opcionais e
            // independentes; `descricao_pagamento` NÃO é a `description` da despesa. Trim
            // e "" → null vêm dos middlewares globais (`Http/Kernel.php`), que já tratam
            // NBSP, U+200B e U+FEFF.
            'comprovante' => 'nullable|image|max:5120',
            'descricao_pagamento' => 'nullable|string|max:500',
            'client_request_id' => 'nullable|uuid',
        ]);

        // Idempotência (feature 20261027-pagamento-no-cadastro-despesa, D7): o cliente manda uma
        // chave por formulário. Reenvio idêntico devolve a despesa que já existe, antes de qualquer
        // regra de competência e antes de gravar arquivo; a mesma chave com outros dados é 409.
        $requestKey = $request->input('client_request_id');
        $fingerprint = null;

        if ($requestKey) {
            $fingerprint = ExpenseRequestFingerprint::make(
                $request->only(['group_id', 'date_payment', 'description', 'expense_type', 'installments', 'total_value', 'user_payer_id', 'payers', 'quotas', 'descricao_pagamento']),
                $request->hasFile('comprovante') ? hash_file('sha256', $request->file('comprovante')->getRealPath()) : null
            );

            if ($existing = $this->expenseByRequestKey($requestKey)) {
                return $this->replayResponse($existing, $fingerprint);
            }
        }

        // `quotas` é opcional: quem envia (o web hoje) vale como sempre; sem ele, o
        // servidor gera as parcelas com o mesmo rateio do web — À Vista e Fixa têm uma
        // só. As regras abaixo (competência fechada, retroativa, `born_paid`) rodam
        // sobre `$quotas`, seja qual for a origem. `has` (e não `filled`) separa
        // "ausente" de "presente e inválido": `quotas: []` segue dando 422 acima.
        $quotas = $request->has('quotas')
            ? $request->input('quotas')
            : $this->generateQuotas(
                $request->total_value,
                $request->expense_type === 'IN_INSTALLMENTS' ? (int) $request->installments : 1,
                $request->date_payment
            );

        if ($request->expense_type === 'IN_INSTALLMENTS') {
            // Parcelada retroativa: a trava de competência fechada não olha o
            // date_payment (mês de início) — o que importa é existir ao menos
            // uma parcela num ciclo ainda aberto. Se TODAS caem em competência
            // já fechada (por data), a despesa só tocaria histórico e é
            // recusada. Ver docs/feature/concluidas/202609/20260903-despesa-parcelada-retroativa/
            // plan.md §1.
            $allQuotasClosed = collect($quotas)->every(
                fn ($quota) => BillingCycle::statusFor(
                    $group->closing_day,
                    Carbon::parse($quota['date_expected']),
                    Carbon::now()
                ) === 'closed'
            );

            if ($allQuotasClosed) {
                return response()->json([
                    'error' => 'Esta despesa parcelada está inteira em competências já fechadas. Para registrá-la, ao menos a última parcela precisa cair num ciclo ainda aberto.',
                ], 422);
            }
        } elseif ($response = $this->rejectIfCompetenceClosed($group, Carbon::parse($request->date_payment))) {
            // IN_CASH / FIXED: criar uma despesa nova com date_payment numa
            // competência já fechada (por data ou fechamento manual) continua
            // recusado — não há "cauda" de parcelas futuras a acompanhar.
            return $response;
        }

        if ($request->expense_type === 'FIXED') {
            if ((int) $request->installments !== 1) {
                return response()->json(['error' => 'Despesa fixa deve ter installments=1.'], 422);
            }

            if (count($quotas) !== 1) {
                return response()->json(['error' => 'Despesa fixa deve ter exatamente 1 quota.'], 422);
            }
        }

        if ($request->expense_type === 'IN_INSTALLMENTS') {
            if (count($quotas) !== (int) $request->installments) {
                return response()->json(['error' => 'A quantidade de quotas deve ser igual a installments.'], 422);
            }

            $quotasSum = round(array_sum(array_column($quotas, 'value_quota')), 2);
            $totalValue = round((float) $request->total_value, 2);

            if (abs($quotasSum - $totalValue) > 0.01) {
                return response()->json(['error' => 'A soma das quotas deve ser igual a total_value.'], 422);
            }
        }

        // Pagamento pedido = há foto OU descrição do pagamento não vazia (R1/R2). Foto
        // inválida já deu 422 na validação acima: nunca é descartada para pagar só com o texto.
        $paymentNote = $request->input('descricao_pagamento');
        $paymentRequested = $request->hasFile('comprovante') || filled($paymentNote);

        // Não existe pagamento antecipado: `pay()` só alcança a competência vigente ou
        // passada, então a ocorrência inicial não pode ser futura (R6). Recusa antes de
        // gravar qualquer coisa, para nunca ficar "despesa criada sem o pagamento pedido".
        if ($paymentRequested && $this->payments()->isFuture(
            $group,
            $this->payments()->earliestOccurrence($request->expense_type, $request->date_payment, $quotas)
        )) {
            return response()->json([
                'error' => 'Não é possível registrar o pagamento de uma competência que ainda não começou. Retire o comprovante e a descrição do pagamento ou use uma data de hoje ou anterior.',
            ], 422);
        }

        // A foto é gravada depois de todas as recusas, e antes da transação: se o banco
        // falhar, o `catch` abaixo a remove.
        $proofPath = $request->hasFile('comprovante')
            ? ProofStorage::store($request->file('comprovante'), $group->id)
            : null;
        $paidAttributes = $paymentRequested
            ? $this->payments()->paidAttributes((int) $request->user_payer_id, $proofPath, $paymentNote)
            : null;
        $paidQuotas = [];

        DB::beginTransaction();

        try {
            $expense = Expense::create([
                'create_date' => now(),
                'date_payment' => $request->date_payment,
                'description' => $request->description,
                'expense_type' => $request->expense_type,
                'installments' => $request->installments,
                'total_value' => $request->total_value,
                'group_id' => $request->group_id,
                'user_creator_id' => auth()->id(),
                'user_payer_id' => $request->user_payer_id,
                'deleted' => false,
                'client_request_id' => $requestKey ?: null,
                'request_fingerprint' => $fingerprint,
            ]);

            // Pagadores
            $expense->payers()->syncWithoutDetaching($request->payers);

            // Quotas
            $bornPaidCount = 0;

            foreach ($quotas as $quotaData) {
                // Regra geral: a despesa nasce PENDENTE — o cliente não decide o
                // status inicial, mesmo enviando 'paid' no payload.
                //
                // Exceção (Parcelada retroativa, plan.md §2): parcela cujo
                // vencimento cai num ciclo já fechado POR DATA nasce quitada
                // pelo credor — nem ele espera receber, nem os devedores devem
                // por ela. Só a contabilização a partir do menor ciclo aberto é
                // pendência real.
                $bornPaid = $request->expense_type === 'IN_INSTALLMENTS'
                    && BillingCycle::statusFor(
                        $group->closing_day,
                        Carbon::parse($quotaData['date_expected']),
                        Carbon::now()
                    ) === 'closed';

                if ($bornPaid) {
                    $bornPaidCount++;
                }

                // Pagamento registrado no cadastro (feature 20261027-pagamento-no-cadastro-despesa,
                // R7): À Vista e Fixa têm uma quota só; a Parcelada é uma compra paga uma vez
                // (cartão), então TODAS as parcelas ficam pagas, inclusive as futuras. A quota
                // `born_paid` (parcela retroativa de ciclo já fechado) segue como sempre e
                // nunca recebe o pagamento.
                $payNow = $paymentRequested && ! $bornPaid;

                $quota = $expense->quotas()->create([
                    'date_expected' => $quotaData['date_expected'],
                    'number' => $quotaData['number'],
                    'value_quota' => $quotaData['value_quota'],
                ] + ($payNow ? $paidAttributes : [
                    'paid' => $bornPaid,
                    'paid_at' => $bornPaid ? now() : null,
                    'paid_by' => $bornPaid ? $request->user_payer_id : null,
                    'born_paid' => $bornPaid,
                ]));

                if ($payNow) {
                    $paidQuotas[] = $quota;
                }
            }

            DB::commit();
        } catch (\Throwable $e) {
            DB::rollBack();

            // O banco não ficou com a despesa: a foto recém-gravada não pode ficar órfã.
            ProofStorage::delete($proofPath);

            // Corrida: outra requisição com a mesma chave confirmou entre a nossa busca e o
            // nosso INSERT. A violação de unicidade vira "devolve a que existe", nunca um 500.
            if ($requestKey && $this->isDuplicateRequestKey($e) && ($existing = $this->expenseByRequestKey($requestKey))) {
                return $this->replayResponse($existing, $fingerprint);
            }

            // A mensagem da exceção fica no log e nunca na resposta (item 080 do backlog;
            // feature 20261027-pagamento-no-cadastro-despesa, D10).
            Log::error('Erro ao criar despesa', ['exception' => $e]);

            return response()->json(['error' => 'Erro ao criar despesa'], 500);
        }

        // Daqui em diante a despesa já existe: nenhum passo pode virar erro para o cliente,
        // senão a tela diria "falhou" com a despesa criada (R11). Cada um vai isolado.
        $this->afterCommit('aviso de despesa criada', fn () => Notifier::expenseCreated($expense));

        // Parcela retroativa nasceu paga em nome do credor sem ele ter registrado a
        // despesa — avisa ele (docs/backlog/expense-parcela-retroativa-paid-by-sem-consentimento.md).
        if ($bornPaidCount > 0) {
            $this->afterCommit('aviso de parcela já paga', fn () => Notifier::expenseBornPaid($expense, $bornPaidCount));
        }

        // O cadastro NÃO sela o ciclo (D8): a selagem continua nos gatilhos de hoje (`pay`,
        // `confirmSettlement`, `close` e a leitura de `summary()`), e o pagamento não quita
        // nenhum acerto entre membros (R14).
        if ($paidQuotas !== []) {
            $this->noticePaymentRegistered($expense, $paidQuotas, $proofPath !== null);
        }

        return response()->json([
            'message' => 'Despesa criada com sucesso',
            'expense_id' => $expense->id,
            'payment_registered' => $paidQuotas !== [],
        ], 201);
    }

    /**
     * Avisos de um cadastro com pagamento (feature 20261027-pagamento-no-cadastro-despesa, R21):
     * UM conjunto por cadastro, nunca um por parcela. `expense_paid` leva o valor das quotas
     * pagas somadas (a compra inteira, na Parcelada); o credor é avisado quando outra pessoa
     * registrou o pagamento em nome dele; e o WhatsApp do comprovante sai uma vez, com a
     * primeira parcela, depois da resposta, como em `pay()`.
     *
     * @param  array<int, Quota>  $paidQuotas
     */
    private function noticePaymentRegistered(Expense $expense, array $paidQuotas, bool $hasProof): void
    {
        $first = collect($paidQuotas)->sortBy('date_expected')->first();
        $total = round(collect($paidQuotas)->sum(fn (Quota $quota) => (float) $quota->value_quota), 2);

        $this->afterCommit('aviso de despesa paga', fn () => Notifier::expensePaid($expense, $first, $total));
        $this->afterCommit('aviso ao credor', fn () => Notifier::expensePaidOnBehalf($expense, count($paidQuotas)));

        if ($hasProof) {
            $this->afterCommit('WhatsApp do comprovante', fn () => $this->dispatchProofNotice($first->id));
        }
    }

    /**
     * Avisa os pagadores por WhatsApp depois da resposta (no-op se a feature está desligada),
     * como `pay()`. O fechamento fica num método próprio e não dentro de outra função: o
     * serializador de closures do `dispatch` pegaria a função de fora e levaria o `$this` do
     * controller junto (estouro de memória).
     */
    private function dispatchProofNotice(int $quotaId): void
    {
        dispatch(function () use ($quotaId) {
            $quota = Quota::with('expense.payers', 'expense.payer')->find($quotaId);

            if ($quota) {
                WhatsAppNotifier::expenseProofPaid($quota);
            }
        })->afterResponse();
    }

    /** A despesa que o usuário autenticado já cadastrou com esta chave, ou null. */
    private function expenseByRequestKey(string $requestKey): ?Expense
    {
        return Expense::where('user_creator_id', auth()->id())
            ->where('client_request_id', $requestKey)
            ->first();
    }

    /**
     * Resposta de um cadastro cuja chave já foi usada. Despesa apagada ou dados diferentes
     * são 409 (nunca recria nem sobrescreve); dados iguais são um reenvio e devolvem a
     * despesa que existe, sem gravar arquivo, criar quotas nem notificar.
     */
    private function replayResponse(Expense $existing, string $fingerprint): \Illuminate\Http\JsonResponse
    {
        if ($existing->deleted) {
            return response()->json([
                'error' => 'Este cadastro já foi processado e a despesa foi apagada. Confira a lista antes de tentar de novo.',
            ], 409);
        }

        if (! hash_equals((string) $existing->request_fingerprint, $fingerprint)) {
            return response()->json([
                'error' => 'Esta despesa já foi cadastrada com outros dados. Confira a lista antes de tentar de novo.',
            ], 409);
        }

        return response()->json([
            'message' => 'Despesa já cadastrada',
            'expense_id' => $existing->id,
            'payment_registered' => $existing->quotas()->where('paid', true)->where('born_paid', false)->exists(),
            'already_existed' => true,
        ]);
    }

    /** Se a exceção é a violação do índice único (criador, chave) de `ex_expenses`. */
    private function isDuplicateRequestKey(\Throwable $e): bool
    {
        return $e instanceof QueryException
            && ($e->errorInfo[1] ?? null) === 1062
            && str_contains($e->getMessage(), 'ex_expenses_creator_request_unique');
    }

    /**
     * Executa um passo que vem DEPOIS do commit do `store()` (aviso, WhatsApp,
     * limpeza). Falha vira `Log::warning` e a resposta de sucesso segue: a despesa
     * já está criada, e o cliente não pode ver erro por causa de um aviso.
     */
    private function afterCommit(string $step, callable $fn): void
    {
        try {
            $fn();
        } catch (\Throwable $e) {
            Log::warning("store: falha no passo pós-commit ({$step})", ['exception' => $e->getMessage()]);
        }
    }

    public function stopRecurrence($expenseId, Request $request)
    {
        $data = $request->validate([
            'year' => 'required|integer',
            'month' => 'required|integer|between:1,12',
        ]);

        $expense = Expense::findOrFail($expenseId);
        $group = Group::findOrFail($expense->group_id);
        $this->authorizeGroupMembership($group);

        if ($expense->expense_type !== 'FIXED') {
            return response()->json(['error' => 'Esta despesa não é do tipo Fixa.'], 422);
        }

        $cutoff = Carbon::create($data['year'], $data['month'], 1);
        $creationMonth = $expense->date_payment->copy()->startOfMonth();

        if ($cutoff->lt($creationMonth)) {
            return response()->json(['error' => 'O mês informado é anterior à criação da despesa.'], 422);
        }

        if (BillingCycle::statusFor($group->closing_day, $cutoff, Carbon::now()) === 'closed') {
            return response()->json(['error' => 'Não é possível interromper a recorrência em um ciclo já fechado.'], 422);
        }

        $expense->update(['fixed_recurrence_ends_at' => $cutoff]);

        return response()->json([
            'message' => 'Recorrência da despesa fixa interrompida.',
            'expense_id' => $expense->id,
            'fixed_recurrence_ends_at' => $cutoff->toDateString(),
        ]);
    }

    public function getMonthlyExpenses($groupId)
    {
        $group = Group::findOrFail($groupId);
        $this->authorizeGroupMembership($group);

        $expenses = DB::table('ex_expenses')
            ->selectRaw('
                YEAR(date_payment) as year,
                MONTH(date_payment) as month,
                COUNT(*) as total_expenses,
                SUM(total_value) as total_value
            ')
            ->where('group_id', $groupId)
            ->where('deleted', false)
            ->groupBy(DB::raw('YEAR(date_payment), MONTH(date_payment)'))
            ->orderByRaw('YEAR(date_payment) DESC, MONTH(date_payment) DESC')
            ->get();

        return response()->json($expenses);
    }

    public function summary($groupId, Request $request)
    {
        $group = Group::findOrFail($groupId);
        $this->authorizeGroupMembership($group);

        $data = $request->validate([
            'cycles_ago' => 'nullable|integer',
        ]);

        $cycle = BillingCycle::cycleFor($group->closing_day, Carbon::now(), $data['cycles_ago'] ?? 0);
        $start = $cycle['start'];
        $end = $cycle['end'];
        $status = $cycle['status'];

        $snapshot = GroupCycleSnapshot::where('group_id', $groupId)
            ->where('cycle_start', $start->toDateString())
            ->first();
        $sealed = $snapshot && $snapshot->isSealed();

        if ($sealed) {
            // Ciclo totalmente quitado → foto imutável.
            $status = 'closed';
            $summary = [
                'totals' => $snapshot->totals,
                'expenses' => $snapshot->expenses,
                'balances' => $snapshot->balances,
                'settlements' => $snapshot->settlements,
            ];
        } elseif ($status === 'closed' || ($status === 'open' && $snapshot && $snapshot->isManuallyClosedAndActive())) {
            // Ciclo fechado (por data ou manualmente) mas ainda não quitado:
            // recalcula AO VIVO para refletir pagamentos/confirmações feitos
            // depois do fechamento (feature 20260902-pagamento-ciclo-fechado).
            // Se com isso a competência ficou toda quitada, sela agora.
            if ($status === 'open') {
                $status = 'closed_manually';
            }

            $summary = $this->cycles()->compute($group, $start, $end);

            if ($this->sealCycleIfSettled($group, $groupId, $start, $end)) {
                $sealed = true;
                $status = 'closed';
            }
        } else {
            $summary = $this->cycles()->compute($group, $start, $end);
        }

        return response()->json([
            'cycle' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'closes_at' => BillingCycle::closesAt($end)->toDateString(),
                'status' => $status,
                'settled' => $sealed,
            ],
            'totals' => $summary['totals'],
            'expenses' => $summary['expenses'],
            'balances' => $summary['balances'],
            'settlements' => $this->cycles()->withConfirmations($groupId, $start, $summary['settlements']),
        ]);
    }

    /**
     * Diz em qual competência o app deve abrir o grupo: o ciclo **fechado (ou na
     * janela de carência) mais recente que ainda não está totalmente quitado**
     * (`cycleIsFullySettled`), varrendo ciclos para trás até o limite do
     * serviço. Se nenhum tem pendência, devolve `0` — o app abre na competência
     * vigente (comportamento pré-`20260902`). Ciclo `future` (ou `open` cuja
     * fronteira ainda não passou) não conta; ciclo selado é pulado. Um ciclo em
     * carência (`open` mas com `end` no passado) conta: a Home fica nele durante
     * os dias de carência e não pula quando ele vira `closed` em `closesAt`.
     *
     * A varredura mora em `CycleSummaryService::focus`. Ver
     * docs/feature/concluidas/202609/20260902-pagamento-ciclo-fechado/plan.md §4 e §10.
     */
    public function focusCycle($groupId)
    {
        $group = Group::findOrFail($groupId);
        $this->authorizeGroupMembership($group);

        return response()->json(['cycles_ago' => $this->cycles()->focus($group)]);
    }

    /**
     * Aba Acompanhamento de Relatórios: os ciclos encerrados (ou em carência) que
     * ainda aguardam quitação, do mais recente ao mais antigo. Só lê: não sela,
     * não grava snapshot e não notifica. A varredura mora em
     * `CycleSummaryService::pendingCycles`. Ver
     * docs/feature/concluidas/202610/20261006-relatorios-acompanhamento-fechamento/plan.md §1.
     */
    public function pendingCycles($groupId)
    {
        $group = Group::findOrFail($groupId);
        $this->authorizeGroupMembership($group);

        return response()->json(['data' => $this->cycles()->pendingCycles($group)]);
    }

    /**
     * Relatório de Fechamento de um ciclo, pelo `cycle_start` que a lista devolveu:
     * o `summary` (totais, despesas, saldos e acertos com as confirmações) mais
     * `creditors`, a agregação credor → devedores em valor bruto por despesa
     * (`ClosingBreakdown`). Serve o ciclo selado, o fechado pendente e o vigente
     * (Prévia). Só lê: ao contrário de `summary()`, **não sela**, não grava
     * snapshot e não notifica — a escolha de fonte é `CycleSummaryService::viewAt`.
     * `cycle_start` que não abre um ciclo do grupo, ou de ciclo futuro, é `404`.
     * A checagem de membro vem antes da validação, para quem é de fora não
     * descobrir nada pelo `422`. Ver
     * docs/feature/concluidas/202610/20261006-relatorios-acompanhamento-fechamento/plan.md §2.
     */
    public function closingReport($groupId, Request $request)
    {
        $group = Group::findOrFail($groupId);
        $this->authorizeGroupMembership($group);

        $data = $request->validate([
            'cycle_start' => 'required|date_format:Y-m-d',
        ]);

        $view = $this->cycles()->viewAt($group, Carbon::createFromFormat('Y-m-d', $data['cycle_start']));

        abort_if($view === null, 404);

        $members = $group->members->map(fn ($member) => [
            'id' => $member->id,
            'name' => $member->name,
            'avatarUrl' => $member->avatar_url,
        ]);

        return response()->json([
            'group' => ['id' => $group->id, 'name' => $group->name],
            'cycle' => $view['cycle'],
            'totals' => $view['summary']['totals'],
            'expenses' => $view['summary']['expenses'],
            'balances' => $view['summary']['balances'],
            'settlements' => $view['summary']['settlements'],
            'creditors' => ClosingBreakdown::build($view['summary']['expenses'], $members)['creditors'],
        ]);
    }

    /**
     * Fechamento manual da competência vigente: congela a composição e os
     * valores considerados (inclusive de despesas FIXED, materializando a
     * Quota de cada ocorrência antes de calcular) num `GroupCycleSnapshot`.
     * Sempre opera sobre a competência que contém "agora" — que, por
     * construção de `BillingCycle::cycleFor` com `cyclesAgo=0`, está sempre
     * aberta em relação a "agora" (não há como chamar isto sobre uma
     * competência já fechada por data). Chamar de novo (re-fechar) recalcula
     * e sobrescreve a foto (upsert), o que cobre "a cópia pode ser
     * atualizada até a virada do mês".
     */
    public function close($groupId)
    {
        $group = Group::findOrFail($groupId);
        $this->authorizeGroupMembership($group);
        abort_if($group->deleted, 404);

        $cycle = BillingCycle::cycleFor($group->closing_day, Carbon::now());
        $start = $cycle['start'];
        $end = $cycle['end'];

        $wasManuallyClosed = GroupCycleSnapshot::where('group_id', $groupId)
            ->where('cycle_start', $start->toDateString())
            ->whereNotNull('closed_manually_at')
            ->exists();

        foreach ($this->cycles()->entries($groupId, $start, $end) as $entry) {
            if ($entry['expense']->expense_type === 'FIXED') {
                $this->materializeFixedOccurrenceQuota($entry['expense'], $entry['date']);
            }
        }

        $summary = $this->cycles()->compute($group, $start, $end);

        $snapshot = GroupCycleSnapshot::updateOrCreate(
            ['group_id' => $groupId, 'cycle_start' => $start->toDateString()],
            [
                'cycle_end' => $end->toDateString(),
                'totals' => $summary['totals'],
                'expenses' => $summary['expenses'],
                'balances' => $summary['balances'],
                'settlements' => $summary['settlements'],
                'closed_manually_at' => Carbon::now(),
                'reopened_at' => null,
            ]
        );

        // Fechar um mês que já está todo pago e com todos os acertos
        // confirmados sela o ciclo direto (sem passar por `closed_manually`).
        $sealed = $this->sealCycleIfSettled($group, $groupId, $start, $end);

        if ($sealed) {
            $snapshot->refresh();
        }

        // Fechamento manual "de verdade" (não selou direto — isso já dispara
        // `cycle_settled` no `sealCycleIfSettled`) e não é um re-fechamento.
        if (! $sealed && ! $wasManuallyClosed) {
            Notifier::cycleClosed($group, $start, auth()->id(), auth()->user()->name);
        }

        return response()->json([
            'cycle' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'closes_at' => BillingCycle::closesAt($end)->toDateString(),
                'status' => $sealed ? 'closed' : 'closed_manually',
                'settled' => $sealed,
            ],
            'totals' => $snapshot->totals,
            'expenses' => $snapshot->expenses,
            'balances' => $snapshot->balances,
            'settlements' => $this->cycles()->withConfirmations($groupId, $start, $snapshot->settlements),
        ]);
    }

    /**
     * Reabertura de um fechamento manual ainda vigente. Só encontra algo pra
     * reabrir quando o fechamento manual pertence à competência que contém
     * "agora" — depois da virada do mês, a competência antiga simplesmente
     * não é mais a que este método consulta (sem precisar de uma checagem
     * extra de `BillingCycle`), então "reabrir competência antiga" já falha
     * por não achar snapshot nenhum aqui.
     */
    public function reopen($groupId)
    {
        $group = Group::findOrFail($groupId);
        $this->authorizeGroupMembership($group);
        abort_if($group->deleted, 404);

        $cycle = BillingCycle::cycleFor($group->closing_day, Carbon::now());
        $start = $cycle['start'];
        $end = $cycle['end'];

        $snapshot = GroupCycleSnapshot::where('group_id', $groupId)
            ->where('cycle_start', $start->toDateString())
            ->first();

        if ($snapshot && $snapshot->isSealed()) {
            return response()->json(['error' => 'Esta competência já foi encerrada.'], 422);
        }

        if (! $snapshot || ! $snapshot->isManuallyClosedAndActive()) {
            return response()->json(['error' => 'Não há fechamento manual ativo para reabrir nesta competência.'], 422);
        }

        $snapshot->update(['reopened_at' => Carbon::now()]);

        $summary = $this->cycles()->compute($group, $start, $end);

        return response()->json([
            'cycle' => ['start' => $start->toDateString(), 'end' => $end->toDateString(), 'closes_at' => BillingCycle::closesAt($end)->toDateString(), 'status' => 'open', 'settled' => false],
            'totals' => $summary['totals'],
            'expenses' => $summary['expenses'],
            'balances' => $summary['balances'],
            'settlements' => $this->cycles()->withConfirmations($groupId, $start, $summary['settlements']),
        ]);
    }

    /**
     * Histórico paginado de ciclos já fechados por data (anteriores à
     * competência vigente) — usado pela tela de Relatórios. Só lê
     * `GroupCycleSnapshot` já persistidos (nunca recalcula), no mesmo formato
     * que `summary()` devolve para a competência vigente, mas sempre com
     * `status: 'closed'`. A competência vigente nunca aparece aqui, mesmo
     * fechada manualmente (`close()`) — fechamento manual é reversível
     * (`reopen()`), então não é "histórico" imutável.
     */
    public function cycleHistory($groupId, Request $request)
    {
        $group = Group::findOrFail($groupId);
        $this->authorizeGroupMembership($group);

        $currentStart = BillingCycle::cycleFor($group->closing_day, Carbon::now())['start'];

        // Só ciclo selado (totalmente quitado) é "histórico" imutável de
        // Relatórios; um ciclo fechado ainda com pendência fica na navegação
        // principal (`focus-cycle`), não aqui.
        $paginator = GroupCycleSnapshot::where('group_id', $groupId)
            ->where('cycle_start', '<', $currentStart->toDateString())
            ->whereNotNull('settled_at')
            ->orderByDesc('cycle_start')
            ->paginate(10);

        $paginator->getCollection()->transform(fn (GroupCycleSnapshot $snapshot) => [
            'cycle' => [
                'start' => $snapshot->cycle_start->toDateString(),
                'end' => $snapshot->cycle_end->toDateString(),
                'status' => 'closed',
            ],
            'totals' => $snapshot->totals,
            'expenses' => $snapshot->expenses,
            'balances' => $snapshot->balances,
            'settlements' => $snapshot->settlements,
        ]);

        return response()->json($paginator);
    }

    /**
     * Árvore Credor→devedores da competência (valores brutos, um por par
     * credor/devedor, sem o netting que `CycleSummaryService::compute`/`settlements`
     * fazem) — usada pela linha expansível do Dashboard. Reaproveita
     * `CycleSummaryService::entries` (mesma fonte de `CycleSummaryService::compute`), mas soma
     * só as participações ainda não pagas, agrupadas por par, sem sugerir
     * menor número de transferências.
     */
    public function grossDebts($groupId, Request $request)
    {
        $group = Group::findOrFail($groupId);
        $this->authorizeGroupMembership($group);

        $data = $request->validate([
            'cycles_ago' => 'nullable|integer',
        ]);

        $cycle = BillingCycle::cycleFor($group->closing_day, Carbon::now(), $data['cycles_ago'] ?? 0);
        $start = $cycle['start'];
        $end = $cycle['end'];

        $sealed = GroupCycleSnapshot::where('group_id', $groupId)
            ->where('cycle_start', $start->toDateString())
            ->whereNotNull('settled_at')
            ->exists();

        $entries = $this->cycles()->entries($groupId, $start, $end);

        $creditors = [];
        foreach ($entries as $entry) {
            if ($entry['paid']) {
                continue;
            }

            $expense = $entry['expense'];
            $creditor = $expense->payer;
            $debtorsInExpense = $expense->payers->reject(fn ($debtor) => $debtor->id === $creditor->id);

            if ($debtorsInExpense->isEmpty()) {
                continue;
            }

            // Mesma fórmula de valuePerPerson em CycleSummaryService::compute(): o valor
            // é dividido por TODOS os participantes (inclusive o credor, se ele
            // também participa da divisão) — só quem não é o credor entra na
            // lista de devedores, mas o valor de cada fatia não muda por isso.
            $participantsCount = max($expense->payers->count(), 1);
            $share = round($entry['value'] / $participantsCount, 2);

            if (! isset($creditors[$creditor->id])) {
                $creditors[$creditor->id] = [
                    'creditor' => ['id' => $creditor->id, 'name' => $creditor->name, 'email' => $creditor->email, 'pix' => $creditor->pix],
                    'debtors' => [],
                ];
            }

            foreach ($debtorsInExpense as $debtor) {
                if (! isset($creditors[$creditor->id]['debtors'][$debtor->id])) {
                    $creditors[$creditor->id]['debtors'][$debtor->id] = ['id' => $debtor->id, 'name' => $debtor->name, 'amount' => 0.0];
                }

                $creditors[$creditor->id]['debtors'][$debtor->id]['amount'] = round(
                    $creditors[$creditor->id]['debtors'][$debtor->id]['amount'] + $share,
                    2
                );
            }
        }

        $tree = collect($creditors)->values()->map(function (array $node) {
            $node['debtors'] = collect($node['debtors'])->values()->all();

            return $node;
        })->all();

        return response()->json([
            'cycle' => [
                'start' => $start->toDateString(),
                'end' => $end->toDateString(),
                'closes_at' => BillingCycle::closesAt($end)->toDateString(),
                'status' => $cycle['status'],
                'settled' => $sealed,
            ],
            'creditors' => $tree,
        ]);
    }

    /**
     * Marca como paga a ocorrência desta despesa numa competência. Só o credor
     * (`user_payer_id`) pode confirmar o pagamento, e — desde a feature
     * `20260902-pagamento-ciclo-fechado` — pode fazê-lo a qualquer momento,
     * inclusive numa competência já fechada (por data ou manualmente). O
     * parâmetro opcional `cycles_ago` (>= 0, default 0) escolhe a competência
     * alvo; `cycles_ago >= 0` nunca resolve para uma competência futura, então
     * não há guard de "futuro" aqui. Despesa `FIXED` nasce sem Quota na
     * competência — materializa antes de marcar.
     *
     * Ao quitar a última pendência da competência, sela o ciclo
     * (`sealCycleIfSettled`): a partir daí ele vira histórico imutável.
     *
     * Comprovante (`comprovante`, foto) e descrição (`descricao`, texto livre
     * de até 500 caracteres) são ambos opcionais no contrato da API. A regra
     * "foto OU descrição" é da interface web (`PaymentProofDialog`), que não
     * deixa confirmar sem um dos dois; o servidor segue permissivo porque
     * exigir isto aqui seria mudança breaking de contrato em produção
     * (`00-constitution.md` §4.1) — decisão D1 em
     * `docs/feature/concluidas/202610/20261021-comprovante-ao-marcar-paga/specify.md` §3.3.
     * Um novo `pay()` sem `descricao` não apaga a nota que já existe; só
     * `unpay()` limpa. Um novo `pay()` com foto nova apaga do disco a foto
     * anterior (depois de o banco apontar para a nova).
     */
    public function pay($expenseId, Request $request)
    {
        $expense = $this->findExpenseForMember($expenseId);

        if (auth()->id() !== $expense->user_payer_id) {
            abort(403, 'Só o credor pode marcar esta despesa como paga.');
        }

        $data = $request->validate([
            'comprovante' => 'nullable|image|max:5120',
            // Trim e "" → null vêm dos middlewares globais (TrimStrings e
            // ConvertEmptyStringsToNull, `Http/Kernel.php`); `max` conta caracteres.
            'descricao' => 'nullable|string|max:500',
            'cycles_ago' => 'nullable|integer|min:0',
        ]);

        $group = $expense->group;
        $cycle = BillingCycle::cycleFor($group->closing_day, Carbon::now(), $data['cycles_ago'] ?? 0);

        $quota = $this->resolveQuotaForCycle($expense, $cycle['start'], $cycle['end']);

        if (! $quota) {
            return response()->json(['error' => 'Esta despesa não tem ocorrência nessa competência.'], 422);
        }

        $update = [
            'paid' => true,
            'paid_at' => Carbon::now(),
            'paid_by' => auth()->id(),
        ];

        if (! empty($data['comprovante'])) {
            $update['payment_proof_path'] = ProofStorage::store($request->file('comprovante'), $expense->group_id);
        }

        // `filled()`, não `empty()`: `empty("0")` é verdadeiro e descartaria a
        // descrição "0" (válida) sem erro.
        if (filled($data['descricao'] ?? null)) {
            $update['payment_note'] = $data['descricao'];
        }

        $wasPaid = (bool) $quota->paid;
        $previousProofPath = $quota->payment_proof_path;

        $quota->update($update);

        // Foto trocada num `pay()` repetido: o arquivo anterior sai do disco só
        // depois de o banco apontar para o novo (nunca perde o comprovante se a
        // gravação falhar). Sem isto o antigo ficava órfão no disco privado
        // (feature `20261022-editar-comprovante-depois-de-paga`, R9). Só sai se
        // nenhuma outra quota o usa: uma compra paga no cadastro aponta várias
        // quotas para o mesmo arquivo (feature `20261027-pagamento-no-cadastro-despesa`, R18).
        if (array_key_exists('payment_proof_path', $update)
            && $previousProofPath
            && $previousProofPath !== $update['payment_proof_path']) {
            ProofStorage::deleteIfUnreferenced($previousProofPath);
        }

        $this->sealCycleIfSettled($group, $group->id, $cycle['start'], $cycle['end']);

        // Só na transição não-paga → paga: re-`pay()` do mesmo quota (ex.:
        // trocar o comprovante) não gera notificação nova.
        if (! $wasPaid) {
            Notifier::expensePaid($expense, $quota);
        }

        // Comprovante anexado pelo credor → avisa os pagadores por WhatsApp,
        // depois da resposta (não segura o request, não quebra o pagamento se
        // a Meta estiver fora). No-op se a feature está desligada.
        if (array_key_exists('payment_proof_path', $update)) {
            $quotaId = $quota->id;

            dispatch(function () use ($quotaId) {
                $quota = Quota::with('expense.payers', 'expense.payer')->find($quotaId);

                if ($quota) {
                    WhatsAppNotifier::expenseProofPaid($quota);
                }
            })->afterResponse();
        }

        return response()->json($quota->fresh()->load('expense'));
    }

    /**
     * Desfaz o pagamento da ocorrência desta despesa numa competência. Mesmas
     * regras de `pay`: só o credor, a qualquer momento (inclusive competência
     * fechada), com `cycles_ago` (>= 0, default 0) escolhendo o alvo. Ao
     * contrário de `pay`, não materializa `Quota` de `FIXED` sob demanda —
     * uma ocorrência ainda virtual nunca está paga (`CycleSummaryService::entries`
     * projeta `paid: false` até existir uma Quota real), então não há o que
     * desfazer nesse caso.
     *
     * Se o `unpay` quebra a quitação total de um ciclo já selado, dessela
     * (`unsealIfBroken`): o ciclo volta a ser recalculado ao vivo.
     *
     * Se a quota tinha comprovante, apaga o arquivo do disco, e a descrição
     * (`payment_note`) é limpa junto — um pagamento desfeito não deve manter
     * "prova" nem observação de um estado que não vale mais.
     */
    public function unpay($expenseId, Request $request)
    {
        $expense = $this->findExpenseForMember($expenseId);

        if (auth()->id() !== $expense->user_payer_id) {
            abort(403, 'Só o credor pode desfazer o pagamento desta despesa.');
        }

        $data = $request->validate([
            'cycles_ago' => 'nullable|integer|min:0',
        ]);

        $group = $expense->group;
        $cycle = BillingCycle::cycleFor($group->closing_day, Carbon::now(), $data['cycles_ago'] ?? 0);

        $quota = $expense->quotas()
            ->whereBetween('date_expected', [$cycle['start']->toDateString(), $cycle['end']->toDateString()])
            ->first();

        if (! $quota || ! $quota->paid) {
            return response()->json(['error' => 'Esta despesa não está paga nessa competência.'], 422);
        }

        $previousProofPath = $quota->payment_proof_path;

        $quota->update([
            'paid' => false,
            'paid_at' => null,
            'paid_by' => null,
            'payment_proof_path' => null,
            'payment_note' => null,
        ]);

        // O arquivo só sai do disco depois de o banco deixar de apontar para ele, e
        // só se nenhuma outra quota da compra o usa (feature
        // `20261027-pagamento-no-cadastro-despesa`, R18).
        ProofStorage::deleteIfUnreferenced($previousProofPath);

        $this->unsealIfBroken($group, $group->id, $cycle['start'], $cycle['end']);

        return response()->json($quota->fresh());
    }

    /**
     * Desfaz de uma vez o pagamento de uma compra parcelada paga no cadastro (feature
     * 20261027-pagamento-no-cadastro-despesa, R19 e D11). Só o credor, e só enquanto nenhuma
     * parcela paga estiver em ciclo encerrado; depois disso a compra é histórico e nada muda.
     * O `unpay` por parcela segue como sempre e não alcança parcelas futuras; este alcança.
     *
     * O arquivo de comprovante, compartilhado pelas parcelas, só sai do disco depois do commit
     * e só se nenhuma outra quota o usa (R18).
     */
    public function undoPurchase($expenseId)
    {
        $expense = $this->findExpenseForMember($expenseId);

        if (auth()->id() !== $expense->user_payer_id) {
            abort(403, 'Só o credor pode desfazer o pagamento desta compra.');
        }

        try {
            $result = $this->payments()->undoPurchase($expense);
        } catch (PurchaseUndoRefused $e) {
            return response()->json(['error' => $e->getMessage()], 422);
        }

        foreach ($result['paths'] as $path) {
            ProofStorage::deleteIfUnreferenced($path);
        }

        return response()->json([
            'message' => 'Pagamento da compra desfeito.',
            'quotas_cleared' => $result['cleared'],
        ]);
    }

    /**
     * Edita a foto do comprovante e a descrição de uma despesa JÁ paga, sem
     * desfazer o pagamento (feature `20261022-editar-comprovante-depois-de-paga`).
     * Só o credor (`user_payer_id`); `cycles_ago` (>= 0, default 0) escolhe a
     * competência, como em `pay()`/`unpay()`.
     *
     * A edição é parcial: `comprovante` (arquivo) troca a foto,
     * `remover_comprovante` verdadeiro a remove, e `descricao` presente
     * (inclusive `null` ou `""`) substitui ou limpa a descrição; o que não vem
     * fica como está. O estado final precisa ter foto OU descrição (422 senão).
     * Ao contrário de `pay()`, esta rota é nova e é estrita.
     *
     * Não altera `paid`, `paid_at` nem `paid_by` e não notifica ninguém nem
     * recalcula a selagem do ciclo: o estado de pago não muda. Recusa ciclo
     * selado (a cópia congelada do ciclo é imutável; para corrigir, o credor
     * desfaz o pagamento, o que já dessela) e não materializa ocorrência `FIXED`
     * (uma ocorrência virtual nunca está paga).
     *
     * Todas as recusas acontecem ANTES de gravar qualquer arquivo. Na gravação,
     * o arquivo anterior só sai do disco depois de o banco apontar para o novo;
     * se o `update` falhar, o arquivo novo é apagado e o antigo permanece.
     */
    public function updatePaymentProof($expenseId, Request $request)
    {
        $expense = $this->findExpenseForMember($expenseId);

        if (auth()->id() !== $expense->user_payer_id) {
            abort(403, 'Só o credor pode editar o comprovante desta despesa.');
        }

        $data = $request->validate([
            'comprovante' => 'nullable|image|max:5120',
            // Multipart envia '1'; JSON envia true. A string "true" não passa em `boolean`.
            'remover_comprovante' => 'nullable|boolean',
            // Trim e "" → null vêm dos middlewares globais (`Http/Kernel.php`).
            'descricao' => 'nullable|string|max:500',
            'cycles_ago' => 'nullable|integer|min:0',
        ]);

        $remove = $request->boolean('remover_comprovante');

        if ($request->hasFile('comprovante') && $remove) {
            return response()->json(['error' => 'Não é possível enviar uma foto nova e remover a foto na mesma edição.'], 422);
        }

        $group = $expense->group;
        $cycle = BillingCycle::cycleFor($group->closing_day, Carbon::now(), $data['cycles_ago'] ?? 0);

        // Toda decisão que depende do estado da quota e a gravação acontecem sob
        // lock de linha (`for update`): se o `unpay` (ou outra edição) estiver
        // no meio, esperamos o commit dele e relemos o estado, em vez de gravar
        // foto e descrição numa quota que acabou de ser despaga (achado B2 da
        // revisão de segurança, TASK-462). Não usamos a contagem de linhas
        // afetadas de um `update ... where paid`: em MySQL um `update` sem mudança
        // de valor devolve 0 e recusaria uma edição legítima sem alteração.
        $outcome = DB::transaction(function () use ($request, $expense, $group, $cycle, $data, $remove) {
            // Mesma busca de `unpay()`: não materializa `FIXED`.
            $quota = $expense->quotas()
                ->whereBetween('date_expected', [$cycle['start']->toDateString(), $cycle['end']->toDateString()])
                ->lockForUpdate()
                ->first();

            if (! $quota || ! $quota->paid) {
                return response()->json(['error' => 'Esta despesa não está paga nessa competência.'], 422);
            }

            $snapshot = GroupCycleSnapshot::where('group_id', $group->id)
                ->where('cycle_start', $cycle['start']->toDateString())
                ->first();

            if ($snapshot && $snapshot->isSealed()) {
                return response()->json(['error' => 'Esta competência já foi encerrada. Desfaça o pagamento para corrigir o comprovante.'], 422);
            }

            $newNote = $request->has('descricao') ? ($data['descricao'] ?? null) : $quota->payment_note;
            $willHaveProof = $request->hasFile('comprovante') || ($quota->payment_proof_path && ! $remove);

            // `filled()`, não `empty()`: `empty("0")` descartaria a descrição "0".
            if (! $willHaveProof && ! filled($newNote)) {
                return response()->json(['error' => 'Informe a foto do comprovante ou uma descrição do pagamento.'], 422);
            }

            $previousPath = $quota->payment_proof_path;
            $newPath = $request->hasFile('comprovante')
                ? ProofStorage::store($request->file('comprovante'), $expense->group_id)
                : null;

            $update = ['payment_note' => $newNote];

            if ($newPath) {
                $update['payment_proof_path'] = $newPath;
            } elseif ($remove) {
                $update['payment_proof_path'] = null;
            }

            try {
                $quota->update($update);
            } catch (\Throwable $e) {
                // O banco não apontou para o arquivo novo: não deixa órfão.
                ProofStorage::delete($newPath);

                throw $e;
            }

            return [$quota, $previousPath, $update];
        });

        if ($outcome instanceof \Illuminate\Http\JsonResponse) {
            return $outcome;
        }

        [$quota, $previousPath, $update] = $outcome;

        // Só depois do commit: se a transação desse rollback, o arquivo antigo
        // continuaria referenciado e não pode ter sido apagado. E só se nenhuma
        // outra quota o usa (compra paga no cadastro, R18).
        if (array_key_exists('payment_proof_path', $update)
            && $previousPath
            && $previousPath !== $update['payment_proof_path']) {
            ProofStorage::deleteIfUnreferenced($previousPath);
        }

        return response()->json($quota->fresh());
    }

    /**
     * Confirma que o usuário autenticado (devedor) pagou via Pix o valor
     * líquido que deve a `to_user_id` num ciclo — conceito distinto de
     * `pay()`/`unpay()`, que confirmam uma despesa específica do lado do
     * credor. O comprovante aqui é obrigatório (é o conteúdo da ação, não
     * um anexo opcional como em `pay()`). A descrição (`descricao`, até 500
     * caracteres) é opcional e vai para `note`; reenviar substitui foto e nota.
     *
     * Desde a feature `20260902-pagamento-ciclo-fechado`, o acerto do devedor
     * só é aceito quando a competência-alvo está **fechada** (`closed` por data
     * ou fechamento manual ativo) — enquanto aberta, os valores ainda podem
     * mudar. `cycles_ago` (>= 0, default 0) escolhe a competência. Só aceita se
     * existir de fato um settlement `from_user_id === auth()->id()` pra esse
     * `to_user_id` naquela competência. Reenviar substitui o comprovante
     * anterior (`updateOrCreate` — sem endpoint de "desfazer", ver
     * docs/feature/concluidas/202608/20260825-pagamentos-grid-pix/specify.md §4). Ao confirmar o
     * último acerto pendente de um ciclo já todo pago, sela o ciclo.
     */
    public function confirmSettlement(Request $request, $groupId)
    {
        $group = Group::findOrFail($groupId);
        $this->authorizeGroupMembership($group);

        $data = $request->validate([
            'to_user_id' => 'required|integer|exists:ex_users,id',
            'comprovante' => 'required|image|max:5120',
            // Opcional; trim e "" → null vêm dos middlewares globais.
            'descricao' => 'nullable|string|max:500',
            'cycles_ago' => 'nullable|integer|min:0',
        ]);

        $cycle = BillingCycle::cycleFor($group->closing_day, Carbon::now(), $data['cycles_ago'] ?? 0);

        $snapshot = GroupCycleSnapshot::where('group_id', $groupId)
            ->where('cycle_start', $cycle['start']->toDateString())
            ->first();

        $cycleIsClosed = $cycle['status'] === 'closed'
            || ($cycle['status'] === 'open' && $snapshot && $snapshot->isManuallyClosedAndActive());

        if (! $cycleIsClosed) {
            return response()->json(['error' => 'O acerto só pode ser confirmado depois que a competência é fechada.'], 422);
        }

        if ($snapshot && $snapshot->isSealed()) {
            return response()->json(['error' => 'Esta competência já foi encerrada.'], 422);
        }

        $summary = $this->cycles()->compute($group, $cycle['start'], $cycle['end']);

        $settlement = collect($summary['settlements'])->first(
            fn ($s) => $s['from_user_id'] === auth()->id() && $s['to_user_id'] === (int) $data['to_user_id']
        );

        if (! $settlement) {
            return response()->json(['error' => 'Não há valor a pagar para esse credor nesta competência.'], 422);
        }

        $key = [
            'group_id' => $groupId,
            'cycle_start' => $cycle['start']->toDateString(),
            'from_user_id' => auth()->id(),
            'to_user_id' => $data['to_user_id'],
        ];

        $previousProofPath = SettlementConfirmation::where($key)->value('proof_path');

        $path = ProofStorage::store($request->file('comprovante'), (int) $groupId);

        $confirmation = SettlementConfirmation::updateOrCreate($key, [
            'cycle_end' => $cycle['end']->toDateString(),
            'amount' => $settlement['amount'],
            'proof_path' => $path,
            // Reenvio substitui o comprovante inteiro: sem `descricao` a nota
            // anterior é limpa, igual à foto que é trocada.
            'note' => $data['descricao'] ?? null,
            'confirmed_at' => Carbon::now(),
        ]);

        if ($previousProofPath && $previousProofPath !== $path) {
            ProofStorage::delete($previousProofPath);
        }

        $this->sealCycleIfSettled($group, $groupId, $cycle['start'], $cycle['end']);

        // Só na 1ª confirmação desse par no ciclo — reenviar o comprovante
        // (`updateOrCreate` que atualiza) não gera notificação nova.
        if ($confirmation->wasRecentlyCreated) {
            Notifier::settlementConfirmed(
                $group,
                (int) $data['to_user_id'],
                auth()->user()->name,
                $settlement['amount'],
                $cycle['start'],
            );
        }

        // Devedor confirmou o acerto com comprovante → avisa o credor por
        // WhatsApp, depois da resposta. No-op se a feature está desligada.
        $confirmationId = $confirmation->id;

        dispatch(function () use ($confirmationId) {
            $confirmation = SettlementConfirmation::with('fromUser', 'toUser')->find($confirmationId);

            if ($confirmation) {
                WhatsAppNotifier::settlementProofConfirmed($confirmation);
            }
        })->afterResponse();

        return response()->json($confirmation->fresh());
    }

    /**
     * Um ciclo [start, end] está "totalmente quitado" quando toda entrada da
     * competência está paga (`totals.pending == 0`) E todo par de `settlements`
     * tem uma `SettlementConfirmation` correspondente. Ciclo sem entradas conta
     * como quitado. Base da selagem (`sealCycleIfSettled`) e do endpoint
     * focus-cycle — ver docs/feature/concluidas/202609/20260902-pagamento-ciclo-fechado/plan.md §0.3.
     *
     * A regra mora em `CycleSummaryService::isFullySettled`. Este método fino
     * continua aqui porque `ExpenseControllerCycleIsFullySettledTest` o chama
     * por reflexão (`new ReflectionMethod(ExpenseController::class, ...)`).
     */
    private function cycleIsFullySettled(Group $group, $groupId, Carbon $start, Carbon $end): bool
    {
        return $this->cycles()->isFullySettled($group, $start, $end);
    }

    /**
     * Resolvido pelo container, sem construtor: os testes instanciam o
     * controller com `new ExpenseController` (reflexão), então ele não pode
     * exigir argumentos.
     */
    private function cycles(): CycleSummaryService
    {
        return app(CycleSummaryService::class);
    }

    /**
     * Regras do pagamento registrado no cadastro (feature
     * 20261027-pagamento-no-cadastro-despesa). Resolvido pelo container, sem
     * construtor, pelo mesmo motivo de `cycles()`.
     */
    private function payments(): ExpensePaymentService
    {
        return app(ExpensePaymentService::class);
    }

    /**
     * Se o ciclo [start, end] está totalmente quitado, congela a foto num
     * `GroupCycleSnapshot` com `settled_at` — a partir daí `summary()` serve
     * essa cópia e o ciclo vira histórico. `updateOrCreate` não passa
     * `closed_manually_at`/`reopened_at`, então preserva o registro de
     * fechamento manual, se houver. Devolve `true` se selou, `false` se ainda
     * há pendência.
     */
    private function sealCycleIfSettled(Group $group, $groupId, Carbon $start, Carbon $end): bool
    {
        if (! $this->cycleIsFullySettled($group, $groupId, $start, $end)) {
            return false;
        }

        $summary = $this->cycles()->compute($group, $start, $end);

        // Ciclo sem despesa REAL nem acerto não vira "histórico selado": não há
        // foto a congelar e um `settled_at` aqui só travaria o `reopen()` à toa.
        // "Real" exclui entry `bornPaid` (parcela retroativa nascida quitada,
        // specify.md §2.3 de 20260904-parcela-retroativa-contabilizacao) — ela
        // fica em `expenses` como linha "Paga" (histórico), mas sozinha não é
        // motivo pra selar o mês nem disparar Notifier::cycleSettled.
        // `cycleIsFullySettled` já devolve `true` pra esse caso (nada pendente),
        // então o `focus-cycle` pula o ciclo mesmo sem selar.
        $hasRealExpense = collect($summary['expenses'])->contains(fn ($expense) => empty($expense['bornPaid']));

        if (! $hasRealExpense && empty($summary['settlements'])) {
            return false;
        }

        $wasSealed = GroupCycleSnapshot::where('group_id', $groupId)
            ->where('cycle_start', $start->toDateString())
            ->first()?->isSealed() ?? false;

        GroupCycleSnapshot::updateOrCreate(
            ['group_id' => $groupId, 'cycle_start' => $start->toDateString()],
            [
                'cycle_end' => $end->toDateString(),
                'totals' => $summary['totals'],
                'expenses' => $summary['expenses'],
                'balances' => $summary['balances'],
                'settlements' => $summary['settlements'],
                'settled_at' => Carbon::now(),
            ]
        );

        // Só na transição não-selado → selado: chamadas repetidas de
        // `sealCycleIfSettled` num ciclo já selado (inclusive as preguiçosas
        // de `summary()`) não geram notificação nova.
        if (! $wasSealed) {
            Notifier::cycleSettled($group, $start);
        }

        return true;
    }

    /**
     * Se o ciclo [start, end] tinha um snapshot selado mas deixou de estar
     * totalmente quitado (ex.: o credor desfez um pagamento), limpa
     * `settled_at` — o ciclo volta a ser recalculado ao vivo em `summary()` e
     * reentra na rotação do focus-cycle. No-op se não há snapshot, se não está
     * selado, ou se continua quitado.
     */
    private function unsealIfBroken(Group $group, $groupId, Carbon $start, Carbon $end): void
    {
        $snapshot = GroupCycleSnapshot::where('group_id', $groupId)
            ->where('cycle_start', $start->toDateString())
            ->first();

        if ($snapshot && $snapshot->isSealed() && ! $this->cycleIsFullySettled($group, $groupId, $start, $end)) {
            $snapshot->update(['settled_at' => null]);
        }
    }

    /**
     * Congela a ocorrência mensal de uma despesa FIXED numa Quota real, usando
     * o `total_value` vigente no momento da chamada. Chamar de novo para o
     * mesmo mês não duplica — devolve a Quota já materializada. A partir daqui,
     * essa competência passa a ler o valor congelado (via `CycleSummaryService::entries`),
     * independente de o `total_value` da despesa mudar depois.
     */
    private function materializeFixedOccurrenceQuota(Expense $expense, Carbon $occurrenceDate): Quota
    {
        $existing = $expense->quotas()
            ->whereDate('date_expected', $occurrenceDate->toDateString())
            ->first();

        if ($existing) {
            return $existing;
        }

        return $expense->quotas()->create([
            'date_expected' => $occurrenceDate->toDateString(),
            'number' => 1,
            'paid' => false,
            'value_quota' => $expense->total_value,
        ]);
    }

    /**
     * Quota que representa esta despesa na competência [start, end] — para
     * `FIXED`, materializa a ocorrência se ainda não existir; para as demais,
     * a Quota já existe desde a criação (uma por competência, por design).
     * `null` se a despesa não tiver ocorrência nessa competência (ex.: FIXED
     * cuja recorrência já foi cortada antes dela). A competência pode ser
     * passada (`cycles_ago > 0` em `pay()`), não só a vigente.
     */
    private function resolveQuotaForCycle(Expense $expense, Carbon $start, Carbon $end): ?Quota
    {
        if ($expense->expense_type === 'FIXED') {
            $entry = $this->cycles()->entries($expense->group_id, $start, $end)
                ->first(fn (array $entry) => $entry['expense']->id === $expense->id);

            return $entry ? $this->materializeFixedOccurrenceQuota($expense, $entry['date']) : null;
        }

        return $expense->quotas()
            ->whereBetween('date_expected', [$start->toDateString(), $end->toDateString()])
            ->first();
    }

    private function findExpenseForMember($id): Expense
    {
        $expense = Expense::where('deleted', false)->findOrFail($id);
        $group = Group::findOrFail($expense->group_id);
        $this->authorizeGroupMembership($group);

        return $expense;
    }

    private function authorizeExpenseOwner(Expense $expense): void
    {
        $isOwner = auth()->id() === $expense->user_creator_id || auth()->id() === $expense->user_payer_id;

        abort_unless($isOwner, 403);
    }
}
