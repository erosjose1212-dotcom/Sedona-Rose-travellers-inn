<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\Shift;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class ShiftAccessService
{
    public function canOperate(User $actor, Shift $shift): bool
    {
        return $actor->can('manage_shifts') && ((config('sedona.continuous_shift_enabled') && $shift->isOpen() && $actor->can('record_payments'))
            || (int) $actor->id === (int) $shift->opened_by
            || ($actor->hasAnyRole(['admin', 'owner']) && $actor->can('manage_any_shifts')));
    }

    public function visibleShifts(User $actor): Builder
    {
        $query = Shift::query();
        if ($actor->hasAnyRole(['admin', 'owner']) && $actor->can('view_all_shifts')) {
            return $query;
        }

        return $query->where(function (Builder $query) use ($actor): void {
            $query->where('opened_by', $actor->id)->orWhere('closed_by', $actor->id)
                ->orWhereIn('id', PaymentEntry::where('user_id', $actor->id)->whereNotNull('shift_id')->select('shift_id'))
                ->orWhereIn('id', Order::where('user_id', $actor->id)->whereNotNull('shift_id')->select('shift_id'))
                ->orWhereIn('id', Expense::where('user_id', $actor->id)->whereNotNull('shift_id')->select('shift_id'));
        });
    }

    public function requireOperatingShift(User $actor, ?string $overrideReason = null, string $action = 'payment'): Shift
    {
        Gate::forUser($actor)->authorize('record_payments');
        $shift = Shift::whereNull('closed_at')->lockForUpdate()->first();
        if (! $shift) {
            throw ValidationException::withMessages(['shift' => $this->noOpenShiftStatus()['message']]);
        }
        if ($shift->is_frozen) {
            throw ValidationException::withMessages(['shift' => $this->frozenShiftStatus($shift)['message']]);
        }
        $this->authorizeOperation($actor, $shift, $overrideReason, $action);

        return $shift;
    }

    /** @return array{state: string, title: string, message: string, shift: ?Shift} */
    public function paymentStatus(User $actor): array
    {
        $shift = Shift::with('openedBy:id,name')->whereNull('closed_at')->first();
        if (! $shift) {
            return $this->noOpenShiftStatus();
        }
        if ($shift->is_frozen) {
            return $this->frozenShiftStatus($shift);
        }
        if (! $actor->can('record_payments')) {
            return ['state' => 'restricted', 'title' => 'Cashier access required',
                'message' => 'A cashier with payment access must settle this bill.', 'shift' => $shift];
        }
        if (! $actor->can('operate-shift', $shift)) {
            return ['state' => 'other_operator', 'title' => 'Drawer assigned to another cashier',
                'message' => 'The active drawer belongs to '.($shift->openedBy?->name ?? 'another cashier').(config('sedona.shift_handover_enabled')
                    ? '. Complete a drawer handover before recording payments.'
                    : '. Ask that cashier or an admin/owner to close it, then start your own cashier shift.'), 'shift' => $shift];
        }

        if (config('sedona.continuous_shift_enabled')) {
            return ['state' => 'ready', 'title' => 'Continuous cashier shift active',
                'message' => 'All cashiers use drawer #'.$shift->id.'. Your payments retain your operator name. Signing out leaves this shift open.', 'shift' => $shift];
        }

        return ['state' => 'ready', 'title' => 'Cashier shift active',
            'message' => 'Payments will be recorded under shift #'.$shift->id.', opened by '.($shift->openedBy?->name ?? 'the assigned cashier').'.', 'shift' => $shift];
    }

    /** @return array{state: string, title: string, message: string, shift: null} */
    private function noOpenShiftStatus(): array
    {
        if (config('sedona.shift_handover_enabled') && Shift::whereIn('handover_status', ['pending', 'disputed', 'variance_approved'])->exists()) {
            return ['state' => 'handover', 'title' => 'Waiting for drawer handover',
                'message' => 'The previous shift is closed and awaiting handover. A different cashier must count and accept the drawer in Shifts & Drawer before payments can be recorded.', 'shift' => null];
        }

        return ['state' => 'no_shift', 'title' => 'Cashier shift not started',
            'message' => 'Start your cashier shift before recording payments.', 'shift' => null];
    }

    /** @return array{state: string, title: string, message: string, shift: Shift} */
    private function frozenShiftStatus(Shift $shift): array
    {
        return ['state' => 'frozen', 'title' => 'Drawer frozen',
            'message' => 'The current drawer is frozen. Ask an admin or owner to review its status in Shifts & Drawer before recording payments.', 'shift' => $shift];
    }

    public function authorizeOperation(User $actor, Shift $shift, ?string $overrideReason, string $action): void
    {
        Gate::forUser($actor)->authorize('operate-shift', $shift);
        if ((int) $actor->id === (int) $shift->opened_by || (config('sedona.continuous_shift_enabled') && $shift->isOpen())) {
            return;
        }
        $reason = trim($overrideReason ?? '');
        if ($reason === '' || mb_strlen($reason) > 500) {
            throw ValidationException::withMessages(['shift_override_reason' => 'Enter a reason of up to 500 characters to operate another cashier\'s drawer.']);
        }
        DB::table('audit_logs')->insert([
            'user_id' => $actor->id, 'event' => 'shift.override',
            'auditable_type' => Shift::class, 'auditable_id' => $shift->id,
            'old_values' => json_encode(['opened_by' => $shift->opened_by], JSON_THROW_ON_ERROR),
            'new_values' => json_encode(['action' => $action, 'reason' => $reason], JSON_THROW_ON_ERROR),
            'created_at' => now(),
        ]);
    }
}
