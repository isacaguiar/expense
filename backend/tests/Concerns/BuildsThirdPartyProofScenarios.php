<?php

namespace Tests\Concerns;

use App\Models\Group;
use App\Models\GroupCycleSnapshot;
use App\Models\GroupMemberResponsible;
use App\Models\SettlementConfirmation;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * Cenário comum dos testes da decisão do credor sobre o comprovante que um **responsável** enviou
 * (feature 20261110-quem-paga-se-declara-e-credor-recusa, TASK-599 em diante). Quem usa o trait precisa
 * usar também `BuildsSettlementScenarios` (`makeSettlement`) e `DatabaseTransactions`, e desfazer o relógio
 * no `tearDown` (`Carbon::setTestNow()`).
 *
 * O cenário fixa o "agora" em 19/08/2026, fecha manualmente agosto/2026 e grava o comprovante do
 * responsável do devedor ao credor, ainda sem decisão.
 */
trait BuildsThirdPartyProofScenarios
{
    /** Quando o comprovante foi enviado (o PHP 8.1 não aceita constante em trait). */
    protected function proofSentAt(): string
    {
        return '2026-08-20 10:00:00';
    }

    protected function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    /**
     * Grupo com o criador, o credor, o devedor e um responsável do devedor; o acerto do devedor ao credor
     * (R$ 100,00) com agosto/2026 fechado (salvo `$closeCycle = false`) e o comprovante que o responsável
     * enviou. `$paid` marca a parcela como paga (sem isso o ciclo nunca sela).
     *
     * @return array{0: Group, 1: User, 2: User, 3: User, 4: SettlementConfirmation, 5: User} grupo, credor, devedor, responsável, comprovante, criador
     */
    protected function thirdPartyScenario(bool $paid = false, bool $closeCycle = true): array
    {
        Carbon::setTestNow('2026-08-19');

        [$creator, $creditor, $debtor, $responsible] = User::factory()->count(4)->create()->all();
        $group = Group::create(['name' => 'Casa', 'created_by' => $creator->id]);
        $group->members()->attach([$creator->id, $creditor->id, $debtor->id, $responsible->id]);
        $expense = $this->makeSettlement($group, $creditor, $debtor, 200, $closeCycle);
        if ($paid) {
            $expense->quotas()->update(['paid' => true]);
        }

        $proof = SettlementConfirmation::create([
            'group_id' => $group->id,
            'cycle_start' => '2026-08-01',
            'cycle_end' => '2026-08-31',
            'from_user_id' => $debtor->id,
            'to_user_id' => $creditor->id,
            'confirmed_by' => $responsible->id,
            'amount' => 100,
            'proof_path' => 'comprovantes/'.$group->id.'/x.jpg',
            'confirmed_at' => $this->proofSentAt(),
        ]);

        return [$group, $creditor, $debtor, $responsible, $proof, $creator];
    }

    /**
     * O mesmo cenário, para quem vai chamar `POST .../settlements/confirm`: com o vínculo "quem paga por
     * quem" (o responsável do devedor, que a rota exige para `from_user_id`) e o arquivo do comprovante
     * no disco falso. `$paid` marca a parcela como paga, para o ciclo poder selar.
     *
     * @return array{0: Group, 1: User, 2: User, 3: User, 4: SettlementConfirmation, 5: User} o mesmo de `thirdPartyScenario`
     */
    protected function linkedScenario(bool $paid = false): array
    {
        Storage::fake('local');

        $scenario = $this->thirdPartyScenario($paid);
        [$group, , $debtor, $responsible, $proof] = $scenario;

        GroupMemberResponsible::create([
            'group_id' => $group->id,
            'user_id' => $debtor->id,
            'responsible_user_id' => $responsible->id,
        ]);
        Storage::disk('local')->put($proof->proof_path, 'comprovante anterior');

        return $scenario;
    }

    /**
     * `$caller` envia o comprovante do acerto ao credor; `$fromUserId` só vai quando é por outra pessoa. Os
     * ids vão como texto, como num pedido HTTP de verdade (o cliente de teste os deixaria como inteiros e
     * esconderia uma comparação `===` sem cast no controller).
     */
    protected function confirmAs(User $caller, Group $group, User $creditor, ?int $fromUserId = null): TestResponse
    {
        $body = ['to_user_id' => (string) $creditor->id, 'comprovante' => UploadedFile::fake()->image('comprovante.jpg')];

        if ($fromUserId !== null) {
            $body['from_user_id'] = (string) $fromUserId;
        }

        return $this->withToken($this->tokenFor($caller))->post("/api/groups/{$group->id}/settlements/confirm", $body);
    }

    /** @return array{from_user_id: int, to_user_id: int} */
    protected function pairBody(User $debtor, User $creditor, array $extra = []): array
    {
        return ['from_user_id' => $debtor->id, 'to_user_id' => $creditor->id] + $extra;
    }

    protected function sealedSnapshot(Group $group): ?GroupCycleSnapshot
    {
        return GroupCycleSnapshot::where('group_id', $group->id)->where('cycle_start', '2026-08-01')->whereNotNull('settled_at')->first();
    }
}
