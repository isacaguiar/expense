<?php

namespace App\Support;

use Illuminate\Support\Collection;

/**
 * Agregação "credor → devedores" do Relatório de Fechamento de um ciclo: quanto
 * cada credor adiantou e, por devedor, quanto este lhe deve **por despesa**
 * (valor bruto), antes de netar um par contra o sentido inverso. O acerto
 * líquido par-a-par continua sendo o `settlements` do `summary`.
 *
 * Classe pura (array entra, array sai), como `BillingCycle`: não consulta banco
 * nem HTTP, e a regra de divisão fica no backend, não no cliente.
 * Ver docs/feature/20261006-relatorios-acompanhamento-fechamento/plan.md §2.2.
 */
class ClosingBreakdown
{
    /**
     * @param  array<int, array<string, mixed>>  $expenses  `expenses` no formato de `summary`, ao vivo ou de snapshot.
     *                                                      Campos lidos: `value`, `bornPaid`, `userPayerId`, `payerName`,
     *                                                      `participantDetails` e `participants`. Snapshot antigo pode
     *                                                      não ter `userPayerId`, `participantDetails` nem `bornPaid`.
     * @param  Collection<int, array{id: int, name: string, avatarUrl: ?string}>  $members  Membros do grupo, para resolver
     *                                                                                      id e avatar quando o snapshot só
     *                                                                                      traz o nome.
     * @return array{creditors: array<int, array{
     *     creditor: array{id: ?int, name: string, avatarUrl: ?string},
     *     advanced_total: float,
     *     debtors: array<int, array{id: ?int, name: string, avatarUrl: ?string, amount: float}>
     * }>}
     */
    public static function build(array $expenses, Collection $members): array
    {
        $byName = $members->keyBy('name');
        $byId = $members->keyBy('id');

        /** @var array<string, array{creditor: array, advanced: float, debtors: array<string, array>}> $creditors */
        $creditors = [];

        foreach ($expenses as $expense) {
            // Parcela retroativa nascida quitada é registro histórico, não dívida
            // (mesma regra de `CycleSummaryService::compute`).
            if (! empty($expense['bornPaid'])) {
                continue;
            }

            $creditor = self::creditorOf($expense, $byId, $byName);
            $creditorKey = self::keyOf($creditor);
            $value = (float) ($expense['value'] ?? 0);

            $participants = self::participantsOf($expense, $byName);
            $slice = $value / max(count($participants), 1);

            $creditors[$creditorKey] ??= ['creditor' => $creditor, 'advanced' => 0.0, 'debtors' => []];
            $creditors[$creditorKey]['advanced'] += $value;

            foreach ($participants as $participant) {
                if (self::keyOf($participant) === $creditorKey
                    || ($participant['id'] === null && $participant['name'] === $creditor['name'])) {
                    continue;
                }

                $debtorKey = self::keyOf($participant);
                $creditors[$creditorKey]['debtors'][$debtorKey] ??= ['person' => $participant, 'amount' => 0.0];
                // Soma a fatia sem arredondar: arredondar por despesa deixaria o
                // total de um par fora do que o acerto líquido reconcilia.
                $creditors[$creditorKey]['debtors'][$debtorKey]['amount'] += $slice;
            }
        }

        $rows = array_map(function (array $row): array {
            $debtors = array_map(
                fn (array $debtor) => $debtor['person'] + ['amount' => round($debtor['amount'], 2)],
                array_values($row['debtors'])
            );

            usort($debtors, fn (array $a, array $b) => [$b['amount'], $a['name']] <=> [$a['amount'], $b['name']]);

            return [
                'creditor' => $row['creditor'],
                'advanced_total' => round($row['advanced'], 2),
                'debtors' => $debtors,
            ];
        }, array_values($creditors));

        usort($rows, fn (array $a, array $b) => [$b['advanced_total'], $a['creditor']['name']] <=> [$a['advanced_total'], $b['creditor']['name']]);

        return ['creditors' => $rows];
    }

    /**
     * Credor da despesa: `userPayerId` quando existe; senão (snapshot antigo) o
     * membro de mesmo nome que `payerName`. Sem nenhum dos dois casando, fica só
     * o nome e o `id` nulo, em vez de derrubar o relatório.
     *
     * @return array{id: ?int, name: string, avatarUrl: ?string}
     */
    private static function creditorOf(array $expense, Collection $byId, Collection $byName): array
    {
        $id = $expense['userPayerId'] ?? null;
        $member = $id !== null ? $byId->get($id) : $byName->get($expense['payerName'] ?? '');

        return [
            'id' => $member['id'] ?? $id,
            'name' => $member['name'] ?? $expense['payerName'] ?? 'Desconhecido',
            'avatarUrl' => $member['avatarUrl'] ?? $expense['payerAvatarUrl'] ?? null,
        ];
    }

    /**
     * Participantes da despesa (o credor entre eles, se rateou). Usa
     * `participantDetails` (com id e avatar); sem ele, resolve os nomes de
     * `participants` pelos membros do grupo. Quem não casa com nenhum membro
     * (ex-membro) entra sem `id` e sem avatar.
     *
     * @return array<int, array{id: ?int, name: string, avatarUrl: ?string}>
     */
    private static function participantsOf(array $expense, Collection $byName): array
    {
        if (! empty($expense['participantDetails'])) {
            return array_map(fn (array $detail) => [
                'id' => $detail['id'] ?? null,
                'name' => $detail['name'],
                'avatarUrl' => $detail['avatarUrl'] ?? null,
            ], array_values($expense['participantDetails']));
        }

        return array_map(function (string $name) use ($byName): array {
            $member = $byName->get($name);

            return [
                'id' => $member['id'] ?? null,
                'name' => $name,
                'avatarUrl' => $member['avatarUrl'] ?? null,
            ];
        }, array_values($expense['participants'] ?? []));
    }

    /** Identidade de uma pessoa: o id quando há, senão o nome. */
    private static function keyOf(array $person): string
    {
        return $person['id'] !== null ? 'id:'.$person['id'] : 'name:'.$person['name'];
    }
}
