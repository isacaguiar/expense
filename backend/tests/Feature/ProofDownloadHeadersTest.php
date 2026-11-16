<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\SettlementConfirmation;
use App\Models\SettlementProofEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

/**
 * Cabeçalhos de proteção do download de comprovante (feature 20261116-endurecer-upload-de-comprovante,
 * plan §1, TASK-641): `nosniff` e CSP `sandbox` nos três tipos da rota assinada, com `inline` mantido,
 * inclusive para um SVG com script que já estava gravado antes da feature (R6).
 */
class ProofDownloadHeadersTest extends TestCase
{
    use DatabaseTransactions;

    private const SVG = '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(document.domain)</script></svg>';

    private function signed(int $groupId, string $type, int|string $id): string
    {
        return URL::temporarySignedRoute('proofs.show', now()->addMinutes(30), [
            'groupId' => $groupId,
            'type' => $type,
            'id' => $id,
        ]);
    }

    /** @return array{0: Group, 1: User, 2: User} */
    private function group(): array
    {
        Storage::fake('local');
        [$debtor, $creditor] = User::factory()->count(2)->create()->all();
        $group = Group::create(['name' => 'Grupo']);
        $group->members()->attach([$debtor->id, $creditor->id]);

        return [$group, $debtor, $creditor];
    }

    /** @return array<string, string> tipo => URL assinada de um arquivo SVG antigo */
    private function urlsByType(string $ext = 'svg'): array
    {
        [$group, $debtor, $creditor] = $this->group();
        $path = fn (string $n) => "comprovantes/{$group->id}/{$n}.{$ext}";

        $expense = Expense::create([
            'create_date' => now(), 'date_payment' => '2026-08-01', 'description' => 'Despesa',
            'expense_type' => 'IN_CASH', 'installments' => 1, 'total_value' => 100, 'group_id' => $group->id,
            'user_creator_id' => $creditor->id, 'user_payer_id' => $creditor->id, 'deleted' => false,
        ]);
        $quota = $expense->quotas()->create([
            'date_expected' => '2026-08-01', 'number' => 1, 'paid' => true, 'value_quota' => 100,
            'payment_proof_path' => $path('quota'),
        ]);
        $confirmation = SettlementConfirmation::create([
            'group_id' => $group->id, 'cycle_start' => '2026-08-01', 'cycle_end' => '2026-08-31',
            'from_user_id' => $debtor->id, 'to_user_id' => $creditor->id, 'confirmed_by' => null,
            'amount' => 50, 'proof_path' => $path('settlement'), 'confirmed_at' => now(),
        ]);
        $entry = SettlementProofEntry::create([
            'group_id' => $group->id, 'cycle_start' => '2026-08-01', 'from_user_id' => $debtor->id,
            'to_user_id' => $creditor->id, 'kind' => SettlementProofEntry::KIND_REPLACED,
            'sent_by' => $debtor->id, 'acted_by' => $debtor->id, 'status_at_exit' => 'sent', 'amount' => 50,
            'proof_path' => $path('history'),
        ]);

        foreach (['quota', 'settlement', 'history'] as $n) {
            Storage::disk('local')->put($path($n), self::SVG);
        }

        return [
            'quota' => $this->signed($group->id, 'quota', $quota->id),
            'settlement' => $this->signed($group->id, 'settlement', $confirmation->id),
            'settlement_history' => $this->signed($group->id, 'settlement_history', $entry->id),
        ];
    }

    public function test_every_download_type_sends_nosniff_and_a_sandboxing_csp(): void
    {
        foreach ($this->urlsByType() as $type => $url) {
            $response = $this->get($url);

            $response->assertOk();
            $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'), $type);
            $this->assertSame("default-src 'none'; sandbox", $response->headers->get('Content-Security-Policy'), $type);
        }
    }

    public function test_an_old_svg_with_a_script_is_still_served_inline_but_contained(): void
    {
        $url = $this->urlsByType()['quota'];

        $response = $this->get($url);

        $response->assertOk();
        $this->assertStringContainsString('inline', (string) $response->headers->get('Content-Disposition'));
        $this->assertSame(self::SVG, $response->streamedContent());
        $this->assertStringContainsString('sandbox', (string) $response->headers->get('Content-Security-Policy'));
    }

    public function test_a_missing_file_stays_a_404_without_leaking_headers_of_a_file(): void
    {
        [$group] = $this->group();

        $this->get($this->signed($group->id, 'quota', 999999999))->assertNotFound();
    }
}
