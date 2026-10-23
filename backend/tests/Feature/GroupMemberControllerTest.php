<?php

namespace Tests\Feature;

use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class GroupMemberControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    public function test_member_can_add_member_to_group(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $existingUser = User::factory()->create();

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/groups/'.$group->id.'/members', ['email' => $existingUser->email]);

        $response->assertStatus(201);
        $this->assertTrue($group->members()->where('user_id', $existingUser->id)->exists());
    }

    public function test_non_member_cannot_add_member_to_group(): void
    {
        Mail::fake();

        $outsider = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $newMemberEmail = 'novo.membro.'.uniqid().'@example.com';

        $response = $this->withToken($this->tokenFor($outsider))
            ->postJson('/api/groups/'.$group->id.'/members', ['email' => $newMemberEmail]);

        $response->assertStatus(404);
        $this->assertDatabaseMissing('ex_users', ['email' => $newMemberEmail]);
        $this->assertSame(0, $group->members()->count());
        Mail::assertNothingSent();
    }

    public function test_cannot_add_member_to_deleted_group(): void
    {
        Mail::fake();

        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste', 'deleted' => true]);
        $group->members()->attach($member->id);
        $newMemberEmail = 'novo.membro.'.uniqid().'@example.com';

        $response = $this->withToken($this->tokenFor($member))
            ->postJson('/api/groups/'.$group->id.'/members', ['email' => $newMemberEmail]);

        $response->assertStatus(404);
        $this->assertDatabaseMissing('ex_users', ['email' => $newMemberEmail]);
        $this->assertSame(1, $group->members()->count());
        Mail::assertNothingSent();
    }

    // --- GET /api/groups/{groupId}/members (item 085: só quem é membro lê a lista) ---

    public function test_non_member_cannot_list_members(): void
    {
        $member = User::factory()->create([
            'pix' => 'pix-do-membro@example.com',
            'whatsapp' => '5511999990001',
        ]);
        $outsider = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($outsider))
            ->getJson('/api/groups/'.$group->id.'/members');

        $response->assertStatus(404);

        // O corpo da resposta não pode carregar nenhum dado do membro.
        $body = $response->getContent();
        $this->assertStringNotContainsString($member->email, $body);
        $this->assertStringNotContainsString('pix-do-membro@example.com', $body);
        $this->assertStringNotContainsString('5511999990001', $body);
    }

    public function test_member_can_list_members(): void
    {
        $member = User::factory()->create();
        $colleague = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach([$member->id, $colleague->id]);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson('/api/groups/'.$group->id.'/members');

        $response->assertStatus(200)
            ->assertJsonCount(2)
            ->assertJsonFragment(['id' => $member->id, 'email' => $member->email])
            ->assertJsonFragment(['id' => $colleague->id, 'email' => $colleague->email]);
    }

    public function test_unauthenticated_cannot_list_members(): void
    {
        $group = Group::create(['name' => 'Grupo de teste']);

        $this->getJson('/api/groups/'.$group->id.'/members')->assertStatus(401);
    }

    // TASK-477 (R2 do specify): para quem não é membro, "o grupo não existe" e "o grupo
    // existe, mas não é seu" têm de ser indistinguíveis, no status e no corpo. Senão a
    // checagem de membro continuaria confirmando a existência do grupo.
    public function test_unknown_group_and_foreign_group_are_indistinguishable(): void
    {
        // Em modo debug o corpo do erro traz `file`, `line` e `trace`, que diferem por
        // construção; o phpunit.xml não fixa APP_DEBUG, então o teste fixa aqui.
        config(['app.debug' => false]);

        $member = User::factory()->create();
        $outsider = User::factory()->create();
        $foreignGroup = Group::create(['name' => 'Grupo alheio']);
        $foreignGroup->members()->attach($member->id);
        $unknownId = Group::max('id') + 1000;
        $token = $this->tokenFor($outsider);

        $unknown = $this->withToken($token)->getJson('/api/groups/'.$unknownId.'/members');
        $foreign = $this->withToken($token)->getJson('/api/groups/'.$foreignGroup->id.'/members');

        $this->assertSame(404, $unknown->getStatusCode());
        $this->assertSame($unknown->getStatusCode(), $foreign->getStatusCode());
        $this->assertSame(
            $unknown->getContent(),
            $foreign->getContent(),
            'o corpo do 404 revela se o grupo existe'
        );
    }

    // TASK-478 (R7 do specify, decisão D1 = B): a leitura de grupo excluído logicamente
    // segue como as demais leituras (`show`, `indexByGroup`, `summary`): o membro recebe
    // 200. Só a escrita (`store`) responde 404 a grupo excluído.
    public function test_member_of_deleted_group_still_lists_members(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo excluído', 'deleted' => true]);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson('/api/groups/'.$group->id.'/members');

        $response->assertStatus(200)
            ->assertJsonCount(1)
            ->assertJsonFragment(['id' => $member->id, 'email' => $member->email]);
    }

    // TASK-478 (R3 e R8): o payload de quem é membro não muda. Trava o que as telas
    // leem e o que NUNCA pode sair (`$hidden` do User). Não é um snapshot exato: uma
    // coluna nova em ex_users não deve quebrar este teste.
    public function test_member_payload_keeps_the_fields_the_web_reads(): void
    {
        $member = User::factory()->create([
            'pix' => 'pix-do-membro@example.com',
            'whatsapp' => '5511999990001',
            'google_id' => 'google-id-do-membro',
            'photo_path' => 'avatars/foto-do-membro.jpg',
        ]);
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson('/api/groups/'.$group->id.'/members');

        $response->assertStatus(200)
            ->assertJsonStructure(['*' => ['id', 'name', 'email', 'pix', 'whatsapp', 'avatar_url']])
            ->assertJsonFragment(['pix' => 'pix-do-membro@example.com', 'whatsapp' => '5511999990001']);

        $item = $response->json('0');
        foreach (['password', 'remember_token', 'google_id', 'photo_path'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $item, "o campo {$hidden} não pode sair na lista de membros");
        }
    }
}
