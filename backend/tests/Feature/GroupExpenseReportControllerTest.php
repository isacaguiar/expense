<?php

namespace Tests\Feature;

use App\Models\Expense;
use App\Models\Group;
use App\Models\User;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Tests\TestCase;

class GroupExpenseReportControllerTest extends TestCase
{
    use DatabaseTransactions;

    private function tokenFor(User $user): string
    {
        return auth('api')->login($user);
    }

    private function createExpense(Group $group, User $payer): Expense
    {
        $expense = Expense::create([
            'create_date' => now(),
            'date_payment' => '2026-03-10',
            'description' => 'Despesa de teste',
            'expense_type' => 'IN_CASH',
            'installments' => 1,
            'total_value' => 100,
            'group_id' => $group->id,
            'user_creator_id' => $payer->id,
            'user_payer_id' => $payer->id,
            'deleted' => false,
        ]);
        $expense->payers()->attach($payer->id);

        return $expense;
    }

    public function test_member_can_view_yearly_report(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);
        $this->createExpense($group, $member);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson("/api/groups/{$group->id}/expenses/report/2026");

        $response->assertStatus(200);
    }

    public function test_non_member_cannot_view_yearly_report(): void
    {
        $outsider = User::factory()->create();
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);
        $this->createExpense($group, $member);

        $response = $this->withToken($this->tokenFor($outsider))
            ->getJson("/api/groups/{$group->id}/expenses/report/2026");

        $response->assertStatus(404);
    }

    public function test_member_can_view_monthly_settlement_report(): void
    {
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);
        $this->createExpense($group, $member);

        $response = $this->withToken($this->tokenFor($member))
            ->getJson("/api/group/{$group->id}/report-monthly/2026");

        $response->assertStatus(200);
    }

    public function test_non_member_cannot_view_monthly_settlement_report(): void
    {
        $outsider = User::factory()->create();
        $member = User::factory()->create();
        $group = Group::create(['name' => 'Grupo de teste']);
        $group->members()->attach($member->id);
        $this->createExpense($group, $member);

        $response = $this->withToken($this->tokenFor($outsider))
            ->getJson("/api/group/{$group->id}/report-monthly/2026");

        $response->assertStatus(404);
    }
}
