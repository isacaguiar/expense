<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class ProofDownloadControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function quotaComComprovante(?string $path = null): array
    {
        $creditor = User::factory()->create();
        $group = Group::create(['name' => 'Grupo']);
        $group->members()->attach($creditor->id);

        $expense = Expense::create([
            'create_date' => now(),
            'date_payment' => '2026-08-01',
            'description' => 'Despesa',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => 100,
            'group_id' => $group->id,
            'user_creator_id' => $creditor->id,
            'user_payer_id' => $creditor->id,
            'deleted' => false,
        ]);
        $quota = $expense->quotas()->create([
            'date_expected' => '2026-08-01',
            'number' => 1,
            'paid' => true,
            'value_quota' => 100,
            'payment_proof_path' => $path ?? "comprovantes/{$group->id}/x.jpg",
        ]);

        return [$group, $quota];
    }

    private function signedQuotaUrl(int $groupId, int|string $quotaId): string
    {
        return URL::temporarySignedRoute('proofs.show', now()->addMinutes(30), [
            'groupId' => $groupId,
            'type' => 'quota',
            'id' => $quotaId,
        ]);
    }

    public function test_baixa_comprovante_novo_do_disco_privado_com_assinatura_valida(): void
    {
        Storage::fake('local');
        [$group, $quota] = $this->quotaComComprovante();
        Storage::disk('local')->put($quota->payment_proof_path, 'CONTEUDO-DO-COMPROVANTE');

        $response = $this->get($this->signedQuotaUrl($group->id, $quota->id));

        $response->assertOk();
        $this->assertSame('CONTEUDO-DO-COMPROVANTE', $response->streamedContent());
    }

    public function test_resolve_comprovante_legado_no_disco_public(): void
    {
        Storage::fake('local');
        Storage::fake('public');
        [$group, $quota] = $this->quotaComComprovante('comprovantes/legado.jpg');
        Storage::disk('public')->put('comprovantes/legado.jpg', 'LEGADO');

        $response = $this->get($this->signedQuotaUrl($group->id, $quota->id));

        $response->assertOk();
        $this->assertSame('LEGADO', $response->streamedContent());
    }

    public function test_sem_assinatura_responde_403(): void
    {
        [$group, $quota] = $this->quotaComComprovante('comprovantes/1/x.jpg');

        $this->get("/api/groups/{$group->id}/proofs/quota/{$quota->id}")->assertForbidden();
    }

    public function test_assinatura_adulterada_responde_403(): void
    {
        [$group, $quota] = $this->quotaComComprovante('comprovantes/1/x.jpg');
        $url = $this->signedQuotaUrl($group->id, $quota->id).'&extra=1';

        $this->get($url)->assertForbidden();
    }

    public function test_type_invalido_responde_404(): void
    {
        $url = URL::temporarySignedRoute('proofs.show', now()->addMinutes(30), [
            'groupId' => 1,
            'type' => 'nope',
            'id' => 1,
        ]);

        $this->get($url)->assertNotFound();
    }

    public function test_quota_de_outro_grupo_responde_404(): void
    {
        Storage::fake('local');
        [$group, $quota] = $this->quotaComComprovante('comprovantes/1/x.jpg');
        Storage::disk('local')->put($quota->payment_proof_path, 'X');

        $this->get($this->signedQuotaUrl($group->id + 999, $quota->id))->assertNotFound();
    }
}
