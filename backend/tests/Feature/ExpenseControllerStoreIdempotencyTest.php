<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\Notification;
use App\Models\Quota;
use App\Models\User;
use App\Support\ExpenseRequestFingerprint;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Idempotência do `POST /api/expenses` (feature 20261027-pagamento-no-cadastro-despesa,
 * specify.md R12 e D7, CA-20 a CA-23, CA-32, CA-33 e CA-35): o mesmo cadastro
 * reenviado com a mesma `client_request_id` não cria outra despesa nem outro
 * pagamento; dados diferentes na mesma chave dão 409; a chave de uma despesa
 * apagada nunca recria.
 */
class ExpenseControllerStoreIdempotencyTest extends TestCase
{
    use DatabaseTransactions;

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    /**
     * @return array{group: Group, creditor: User, debtor: User}
     */
    private function scenario(): array
    {
        Carbon::setTestNow('2026-08-19 12:00:00');
        Storage::fake('local');

        $creditor = User::factory()->create();
        $debtor = User::factory()->create();
        $group = Group::create(['name' => 'República']);
        $group->members()->attach([$creditor->id, $debtor->id]);

        return ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor];
    }

    /** @return array<string, mixed> */
    private function body(Group $group, User $creditor, User $debtor, string $key, array $overrides = []): array
    {
        return array_merge([
            'date_payment' => '2026-08-10',
            'description' => 'Conta de luz',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => 150,
            'group_id' => $group->id,
            'user_creator_id' => $creditor->id,
            'user_payer_id' => $creditor->id,
            'payers' => [$creditor->id, $debtor->id],
            'client_request_id' => $key,
        ], $overrides);
    }

    private function token(User $user): string
    {
        return auth('api')->login($user);
    }

    /** @return array{expenses: int, payers: int, quotas: int, files: int, notifications: int} */
    private function counts(): array
    {
        return [
            'expenses' => Expense::count(),
            'payers' => DB::table('ex_expenses_payers')->count(),
            'quotas' => Quota::count(),
            'files' => count(Storage::disk('local')->allFiles()),
            'notifications' => Notification::count(),
        ];
    }

    private function postWithPhoto(string $token, array $body, UploadedFile $photo)
    {
        return $this->withToken($token)->post('/api/expenses', $body + ['comprovante' => $photo], ['Accept' => 'application/json']);
    }

    // --- CA-20: reenvio idêntico -------------------------------------------------------------

    public function test_the_same_key_and_body_twice_creates_one_expense_and_returns_the_same_id(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $body = $this->body($group, $creditor, $debtor, (string) Str::uuid(), ['descricao_pagamento' => 'Cartão']);
        $token = $this->token($creditor);

        $first = $this->withToken($token)->postJson('/api/expenses', $body)->assertStatus(201);
        $second = $this->withToken($token)->postJson('/api/expenses', $body)
            ->assertStatus(200)
            ->assertJsonPath('already_existed', true)
            ->assertJsonPath('payment_registered', true);

        $this->assertSame($first->json('expense_id'), $second->json('expense_id'));
        $this->assertSame(1, Expense::count());
        $this->assertSame(1, Quota::count());
        $this->assertNull($first->json('already_existed'), 'a primeira resposta não é um replay');
    }

    public function test_the_same_photo_sent_again_under_another_file_name_is_still_a_replay(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $body = $this->body($group, $creditor, $debtor, (string) Str::uuid());
        $token = $this->token($creditor);

        $first = $this->postWithPhoto($token, $body, UploadedFile::fake()->image('primeira.jpg', 20, 20))->assertStatus(201);
        $second = $this->postWithPhoto($token, $body, UploadedFile::fake()->image('outro-nome.jpg', 20, 20))
            ->assertStatus(200)
            ->assertJsonPath('already_existed', true);

        $this->assertSame($first->json('expense_id'), $second->json('expense_id'));
        $this->assertSame(1, $this->counts()['files'], 'o conteúdo é o mesmo: um só arquivo');
    }

    // --- CA-32: o replay não grava, não cria e não avisa --------------------------------------

    public function test_a_replay_stores_no_file_creates_no_rows_and_notifies_nobody(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $body = $this->body($group, $creditor, $debtor, (string) Str::uuid(), ['descricao_pagamento' => 'Cartão']);
        $token = $this->token($creditor);

        $this->postWithPhoto($token, $body, UploadedFile::fake()->image('c.jpg', 20, 20))->assertStatus(201);
        $afterFirst = $this->counts();

        $this->postWithPhoto($token, $body, UploadedFile::fake()->image('c.jpg', 20, 20))->assertStatus(200);

        $this->assertSame($afterFirst, $this->counts());
    }

    // --- CA-22: a mesma chave com outros dados ----------------------------------------------

    public function test_the_same_key_with_a_different_body_is_refused_with_409_and_changes_nothing(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $key = (string) Str::uuid();
        $token = $this->token($creditor);

        $id = $this->withToken($token)->postJson('/api/expenses', $this->body($group, $creditor, $debtor, $key))->assertStatus(201)->json('expense_id');
        $before = $this->counts();

        $this->withToken($token)->postJson('/api/expenses', $this->body($group, $creditor, $debtor, $key, ['total_value' => 151]))
            ->assertStatus(409)
            ->assertJsonPath('error', 'Esta despesa já foi cadastrada com outros dados. Confira a lista antes de tentar de novo.');

        $this->assertSame($before, $this->counts());
        $this->assertSame('150.00', Expense::findOrFail($id)->total_value);
    }

    public function test_the_same_key_with_a_different_photo_is_refused_with_409(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $body = $this->body($group, $creditor, $debtor, (string) Str::uuid());
        $token = $this->token($creditor);

        $this->postWithPhoto($token, $body, UploadedFile::fake()->image('a.jpg', 20, 20))->assertStatus(201);
        $before = $this->counts();

        $this->postWithPhoto($token, $body, UploadedFile::fake()->image('a.jpg', 40, 40))->assertStatus(409);

        $this->assertSame($before, $this->counts());
    }

    public function test_the_same_key_from_another_user_does_not_collide(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $key = (string) Str::uuid();

        $this->withToken($this->token($creditor))->postJson('/api/expenses', $this->body($group, $creditor, $debtor, $key))->assertStatus(201);
        $this->withToken($this->token($debtor))
            ->postJson('/api/expenses', $this->body($group, $debtor, $creditor, $key, ['user_creator_id' => $debtor->id]))
            ->assertStatus(201);

        $this->assertSame(2, Expense::where('client_request_id', $key)->count());
    }

    // --- CA-23: sem chave, o comportamento é o de hoje -------------------------------------

    public function test_without_a_key_every_call_creates_an_expense(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $body = $this->body($group, $creditor, $debtor, 'x');
        unset($body['client_request_id']);
        $token = $this->token($creditor);

        $first = $this->withToken($token)->postJson('/api/expenses', $body)->assertStatus(201);
        $second = $this->withToken($token)->postJson('/api/expenses', $body)->assertStatus(201);

        $this->assertNotSame($first->json('expense_id'), $second->json('expense_id'));
        $this->assertSame(2, Expense::count());
        $this->assertSame(0, Expense::whereNotNull('client_request_id')->count());
    }

    // --- CA-33: a chave de uma despesa apagada nunca recria --------------------------------

    public function test_the_key_of_a_deleted_expense_is_refused_with_409_and_nothing_is_recreated(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $body = $this->body($group, $creditor, $debtor, (string) Str::uuid());
        $token = $this->token($creditor);

        $id = $this->withToken($token)->postJson('/api/expenses', $body)->assertStatus(201)->json('expense_id');
        Expense::findOrFail($id)->update(['deleted' => true]);
        $before = $this->counts();

        $this->withToken($token)->postJson('/api/expenses', $body)
            ->assertStatus(409)
            ->assertJsonPath('error', 'Este cadastro já foi processado e a despesa foi apagada. Confira a lista antes de tentar de novo.');

        $this->assertSame($before, $this->counts());
    }

    // --- O replay vem antes das regras de competência --------------------------------------

    public function test_a_replay_still_answers_after_the_competence_has_closed_since(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $body = $this->body($group, $creditor, $debtor, (string) Str::uuid());
        $token = $this->token($creditor);

        $id = $this->withToken($token)->postJson('/api/expenses', $body)->assertStatus(201)->json('expense_id');

        // Agosto fecha em 05/09; hoje a mesma despesa seria recusada, mas o reenvio é só um replay.
        Carbon::setTestNow('2026-10-19 12:00:00');

        // O token da data antiga expirou junto com o relógio: emite outro.
        $this->withToken($this->token($creditor))->postJson('/api/expenses', $body)
            ->assertStatus(200)
            ->assertJsonPath('expense_id', $id);
    }

    // --- CA-21 e CA-35: a corrida entre duas requisições ----------------------------------

    /**
     * Faz o disco falso, no instante em que a foto é gravada (depois da busca da chave e
     * antes da transação), inserir a despesa de uma requisição concorrente que já
     * confirmou. É a janela real da corrida.
     */
    private function raceOnPhotoWrite(callable $competing): void
    {
        $real = Storage::fake('local');
        $racing = \Mockery::mock($real);
        $racing->shouldReceive('putFileAs')->andReturnUsing(function (...$arguments) use ($real, $competing) {
            $competing();

            return $real->putFileAs(...$arguments);
        });
        Storage::set('local', $racing);
    }

    private function insertCompetingExpense(Group $group, User $creditor, string $key, string $fingerprint): int
    {
        return DB::table('ex_expenses')->insertGetId([
            'create_date' => now(),
            'date_payment' => '2026-08-10',
            'description' => 'Conta de luz',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => 150,
            'group_id' => $group->id,
            'user_creator_id' => $creditor->id,
            'user_payer_id' => $creditor->id,
            'deleted' => false,
            'client_request_id' => $key,
            'request_fingerprint' => $fingerprint,
        ]);
    }

    public function test_the_loser_of_a_race_with_the_same_body_answers_with_the_winner_and_removes_its_own_file(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $key = (string) Str::uuid();
        $body = $this->body($group, $creditor, $debtor, $key, ['descricao_pagamento' => 'Cartão']);
        $photo = UploadedFile::fake()->image('c.jpg', 20, 20);

        $fingerprint = ExpenseRequestFingerprint::make(
            collect($body)->only(['group_id', 'date_payment', 'description', 'expense_type', 'installments', 'total_value', 'user_payer_id', 'payers', 'descricao_pagamento'])->all(),
            hash_file('sha256', $photo->getRealPath())
        );

        $winnerId = null;
        $this->raceOnPhotoWrite(function () use ($group, $creditor, $key, $fingerprint, &$winnerId) {
            $winnerId = $this->insertCompetingExpense($group, $creditor, $key, $fingerprint);
        });

        $response = $this->postWithPhoto($this->token($creditor), $body, $photo)
            ->assertStatus(200)
            ->assertJsonPath('already_existed', true);

        $this->assertSame($winnerId, $response->json('expense_id'));
        $this->assertSame(1, Expense::count(), 'só a despesa da vencedora');
        $this->assertSame(0, Quota::count(), 'a transação da perdedora foi desfeita por inteiro');
        $this->assertSame(0, count(Storage::disk('local')->allFiles()), 'a perdedora remove o próprio arquivo: nenhum órfão');
    }

    public function test_the_loser_of_a_race_with_a_different_body_gets_409_and_leaves_no_file(): void
    {
        ['group' => $group, 'creditor' => $creditor, 'debtor' => $debtor] = $this->scenario();
        $key = (string) Str::uuid();
        $body = $this->body($group, $creditor, $debtor, $key, ['descricao_pagamento' => 'Cartão']);

        $this->raceOnPhotoWrite(fn () => $this->insertCompetingExpense($group, $creditor, $key, hash('sha256', 'outro corpo')));

        $this->postWithPhoto($this->token($creditor), $body, UploadedFile::fake()->image('c.jpg', 20, 20))
            ->assertStatus(409);

        $this->assertSame(1, Expense::count());
        $this->assertSame(0, count(Storage::disk('local')->allFiles()));
    }
}
