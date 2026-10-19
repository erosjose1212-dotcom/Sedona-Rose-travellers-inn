<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\PosItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\TestWith;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ApprovalQueueTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    #[TestWith(['admin'])]
    #[TestWith(['owner'])]
    public function test_reviewers_see_oldest_pending_requests_first_with_names_and_status_counts(string $role): void
    {
        $reviewer = $this->actor($role);
        $requester = $this->actor('cashier');
        $newer = ApprovalRequest::factory()->create(['requested_by' => $requester->id, 'created_at' => now()->subHour()]);
        $older = ApprovalRequest::factory()->create(['requested_by' => $requester->id, 'created_at' => now()->subHours(2)]);
        ApprovalRequest::factory()->create(['status' => 'approved']);
        ApprovalRequest::factory()->create(['status' => 'executed']);

        $this->actingAs($reviewer)->get(route('approvals.index'))->assertOk()
            ->assertViewHas('statusFilter', 'pending')
            ->assertViewHas('statusCounts', ['pending' => 2, 'approved' => 1, 'history' => 1, 'all' => 4])
            ->assertViewHas('requests', fn ($requests): bool => $requests->modelKeys() === [$older->id, $newer->id])
            ->assertSee($requester->name)->assertSee($older->source->name)
            ->assertSeeInOrder(['Property review queue', 'Apply stock update now', 'Create a new request']);
    }

    public function test_status_filters_and_pagination_keep_review_and_history_separate(): void
    {
        $reviewer = $this->actor('admin');
        $sample = ApprovalRequest::factory()->create(['status' => 'approved']);
        ApprovalRequest::factory()->count(20)->create($sample->only(['source_type', 'source_id', 'requested_by', 'status']));
        $rejected = ApprovalRequest::factory()->create(['status' => 'rejected']);
        $cancelled = ApprovalRequest::factory()->create(['status' => 'cancelled']);

        $this->actingAs($reviewer)->get(route('approvals.index', ['status' => 'approved']))->assertOk()
            ->assertViewHas('requests', fn ($requests): bool => $requests->total() === 21 && $requests->count() === 20)
            ->assertSee('status=approved&amp;page=2', false)->assertSee('Apply stock update now');
        $this->get(route('approvals.index', ['status' => 'history']))->assertOk()
            ->assertViewHas('requests', fn ($requests): bool => $requests->modelKeys() === [$cancelled->id, $rejected->id])
            ->assertDontSee('Apply approved action')->assertDontSee('Approve request');
        $this->get(route('approvals.index', ['status' => ['invalid']]))->assertOk()->assertViewHas('statusFilter', 'pending');
    }

    public function test_cashier_results_and_counts_never_include_other_staff_requests(): void
    {
        $cashier = $this->actor('cashier');
        $own = ApprovalRequest::factory()->create(['requested_by' => $cashier->id, 'status' => 'approved']);
        ApprovalRequest::factory()->create(['reason' => 'Private request from another operator']);

        $this->actingAs($cashier)->get(route('approvals.index'))->assertOk()
            ->assertViewHas('statusFilter', 'all')
            ->assertViewHas('statusCounts', ['pending' => 0, 'approved' => 1, 'history' => 0, 'all' => 1])
            ->assertViewHas('requests', fn ($requests): bool => $requests->modelKeys() === [$own->id])
            ->assertDontSee('Private request from another operator')->assertSee('Your requests and outcomes');
        $this->get(route('approvals.index', ['status' => 'pending']))->assertOk()
            ->assertViewHas('requests', fn ($requests): bool => $requests->isEmpty());
    }

    public function test_own_request_hides_review_controls_and_server_rejects_self_review(): void
    {
        $reviewer = $this->actor('owner');
        $approval = ApprovalRequest::factory()->create(['requested_by' => $reviewer->id]);

        $this->actingAs($reviewer)->get(route('approvals.index'))->assertOk()->assertDontSee('Approve request')
            ->assertSee('No approval is required.');
        $this->post(route('approvals.review', $approval), ['decision' => 'approve', 'note' => 'Verified'])
            ->assertForbidden();
        $this->assertSame('pending', $approval->fresh()->status);
    }

    public function test_approval_redirects_to_ready_to_apply_and_execution_records_the_change_once(): void
    {
        $reviewer = $this->actor('admin');
        $requester = $this->actor('cashier');
        $approval = ApprovalRequest::factory()->create(['requested_by' => $requester->id]);
        $item = PosItem::findOrFail($approval->source_id);

        $this->actingAs($reviewer)->post(route('approvals.review', $approval), ['decision' => 'approve', 'note' => 'Delivery verified'])
            ->assertRedirect(route('approvals.index', ['status' => 'approved']))->assertSessionHasNoErrors();
        $this->assertSame(10, $item->fresh()->stock_quantity);
        $this->get(route('approvals.index', ['status' => 'approved']))->assertSee('Delivery verified')->assertSee($reviewer->name);
        $data = ['operation_key' => (string) Str::uuid()];
        $this->post(route('approvals.execute', $approval), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->post(route('approvals.execute', $approval), $data)->assertRedirect()->assertSessionHasNoErrors();
        $this->assertSame(20, $item->fresh()->stock_quantity);
        $this->assertDatabaseCount('inventory_events', 1);
        $this->get(route('approvals.index', ['status' => 'history']))->assertOk()->assertSee('Applied by '.$reviewer->name)
            ->assertDontSee('Apply approved action');
    }

    public function test_readable_amounts_and_stock_names_remain_escaped(): void
    {
        $reviewer = $this->actor('admin');
        $sample = ApprovalRequest::factory()->create();
        $item = $sample->source;
        $item->update(['name' => '<script>alert("stock")</script>']);
        $sample->update(['kind' => 'stock_recount', 'payload' => ['counts' => [$item->id => 25], 'amount' => 130], 'reason' => '<script>alert("reason")</script>']);

        $this->actingAs($reviewer)->get(route('approvals.index'))->assertOk()->assertSee('₱130.00')
            ->assertSee('Stock: '.$item->name)->assertSee('25')->assertDontSee('<script>alert("stock")</script>', false)
            ->assertDontSee('<script>alert("reason")</script>', false);
    }

    private function actor(string $role): User
    {
        $user = User::factory()->create(['name' => ucfirst($role).' Operator']);
        $user->assignRole(Role::findOrCreate($role, 'web'));
        foreach (['manage_folios', 'manage_shifts', 'view_all_shifts', 'post_pos_sales'] as $permission) {
            $user->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }

        return $user;
    }
}
