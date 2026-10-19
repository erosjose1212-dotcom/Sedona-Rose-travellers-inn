<?php

namespace Tests\Feature;

use App\Models\ApprovalRequest;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Room;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class OwnerFolioApprovalTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_request_is_automatically_approved_and_can_be_applied_once(): void
    {
        [$owner, $folio] = $this->fixture();
        $data = $this->requestData($folio);

        $this->actingAs($owner)->post(route('approvals.store'), $data)->assertRedirect(route('approvals.index', ['status' => 'approved']))->assertSessionHasNoErrors();
        $this->post(route('approvals.store'), $data)->assertSessionHasNoErrors();

        $approval = ApprovalRequest::sole();
        $this->assertSame('approved', $approval->status);
        $this->assertSame($owner->id, $approval->reviewed_by);
        $this->assertSame('NONE', $folio->fresh()->discount_type);
        $this->assertDatabaseHas('audit_logs', ['event' => 'approval.approved', 'auditable_id' => $approval->id, 'user_id' => $owner->id]);
        $execute = ['operation_key' => (string) Str::uuid()];
        $this->post(route('approvals.execute', $approval), $execute)->assertSessionHasNoErrors();
        $this->post(route('approvals.execute', $approval), $execute)->assertSessionHasNoErrors();
        $this->post(route('approvals.execute', $approval), ['operation_key' => (string) Str::uuid()])->assertSessionHasErrors('approval');
        $this->assertDatabaseHas('folios', ['id' => $folio->id, 'discount_type' => 'SENIOR', 'discount_override_amount' => 40, 'discount_amount' => 40, 'net_total' => 355]);
        $this->assertSame('executed', $approval->fresh()->status);
        $this->assertSame(1, DB::table('audit_logs')->where('event', 'approval.executed')->count());
    }

    public function test_owner_can_review_their_existing_pending_folio_request(): void
    {
        [$owner, $folio] = $this->fixture();
        $this->actingAs($owner)->post(route('approvals.store'), $this->requestData($folio))->assertSessionHasNoErrors();
        $approval = ApprovalRequest::sole();
        $approval->update(['status' => 'pending', 'reviewed_by' => null, 'reviewed_at' => null, 'review_note' => null]);

        $this->get(route('approvals.index'))->assertSee('Approve request');
        $this->post(route('approvals.review', $approval), ['decision' => 'approve', 'note' => 'Owner verified the guest discount'])
            ->assertRedirect(route('approvals.index', ['status' => 'approved']))->assertSessionHasNoErrors();
        $this->post(route('approvals.execute', $approval), ['operation_key' => (string) Str::uuid()])->assertSessionHasNoErrors();

        $this->assertSame('executed', $approval->fresh()->status);
        $this->assertSame('355.00', $folio->fresh()->net_total);
    }

    public function test_owner_approval_still_rejects_a_changed_source_and_a_check_in_time_override(): void
    {
        [$owner, $folio] = $this->fixture();
        $this->actingAs($owner)->post(route('approvals.store'), $this->requestData($folio))->assertSessionHasNoErrors();
        $approval = ApprovalRequest::sole();
        $folio->update(['room_charge' => 400]);

        $this->post(route('approvals.execute', $approval), ['operation_key' => (string) Str::uuid()])->assertSessionHasErrors('approval');
        $data = $this->requestData($folio);
        $data['payload']['checked_in_at'] = now()->subHour()->format('Y-m-d H:i:s');
        $this->post(route('approvals.store'), $data)->assertSessionHasErrors('checked_in_at');

        $this->assertSame('approved', $approval->fresh()->status);
        $this->assertSame('NONE', $folio->fresh()->discount_type);
        $this->assertDatabaseCount('approval_requests', 1);
    }

    /** @return array{User, Folio} */
    private function fixture(): array
    {
        $this->withoutVite();
        $this->travelTo('2026-10-13 12:00:00');
        $owner = User::factory()->create(['name' => 'Sedona Owner']);
        $owner->assignRole(Role::findOrCreate('owner', 'web'));
        foreach (['manage_folios', 'manage_shifts', 'view_all_shifts'] as $permission) {
            $owner->givePermissionTo(Permission::findOrCreate($permission, 'web'));
        }
        $room = Room::create(['number' => '27', 'name' => 'Classic', 'type' => 'Classic Room', 'floor' => 1, 'status' => 'occupied', 'base_rate_3h' => 395]);
        $guest = Guest::create(['name' => 'Guest Room 27', 'headcount' => 1]);
        $folio = Folio::create(['room_id' => $room->id, 'guest_id' => $guest->id, 'user_id' => $owner->id, 'transaction_id' => Folio::generateTransactionId(), 'checked_in_at' => now(), 'expected_checkout_at' => now()->addHours(3), 'rate_tier' => '3h', 'room_charge' => 395, 'status' => 'active', 'discount_type' => 'NONE']);

        return [$owner, $folio];
    }

    /** @return array<string, mixed> */
    private function requestData(Folio $folio): array
    {
        return ['operation_key' => (string) Str::uuid(), 'kind' => 'folio_override', 'source_id' => $folio->id,
            'payload' => ['discount_type' => 'SENIOR', 'discount_id_ref' => '2020-108', 'discount_override_amount' => 40], 'reason' => 'Owner corrected the discount charge'];
    }
}
