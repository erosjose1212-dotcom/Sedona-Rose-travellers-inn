<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Room;
use App\Services\ApprovalService;
use App\Services\AuditService;
use App\Services\BillingService;
use App\Services\DiscountService;
use App\Services\FinancialPostingService;
use App\Services\InventoryService;
use App\Services\ShiftAccessService;
use App\Services\StayCancellationService;
use App\Services\StayRateService;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CheckInController extends Controller
{
    public function __construct(private FinancialPostingService $posting, private AuditService $audit, private StayRateService $stayRates, private InventoryService $inventory) {}

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'room_id' => 'required|exists:rooms,id', 'guest_name' => 'nullable|string|max:255',
            'guest_contact' => 'nullable|string|max:50', 'headcount' => 'required|integer|min:1|max:8',
            'rate_tier' => 'required|in:3h,4h,6h,12h,24h,open'.(config('sedona.midnight_promo_enabled') ? ',promo' : ''), 'is_senior' => 'nullable|boolean',
            'is_pwd' => 'nullable|boolean', 'discount_id_ref' => 'nullable|string|max:100',
            'booking_id' => 'nullable|exists:bookings,id', 'checked_in_at' => 'prohibited',
            'record_earlier_arrival' => 'prohibited', 'actual_checked_in_at' => 'prohibited', 'late_entry_reason' => 'prohibited',
            'operation_key' => 'nullable|uuid',
        ]);
        if (($request->boolean('is_senior') || $request->boolean('is_pwd')) && trim($data['discount_id_ref'] ?? '') === '') {
            throw ValidationException::withMessages(['discount_id_ref' => 'Record the statutory discount ID reference.']);
        }
        $checkIn = function () use ($request, $data): array {
            $room = Room::whereKey($data['room_id'])->lockForUpdate()->firstOrFail();
            $this->requireAvailable($room);
            $booking = null;
            if (! empty($data['booking_id'])) {
                $booking = Booking::whereKey($data['booking_id'])->lockForUpdate()->firstOrFail();
                if ($booking->room_id !== $room->id || $booking->status !== 'scheduled') {
                    throw ValidationException::withMessages(['booking_id' => 'This reservation is not scheduled for this room.']);
                }
                if ($booking->headcount < 1 || $booking->headcount > 8) {
                    throw ValidationException::withMessages(['headcount' => 'Reservations must contain between 1 and 8 guests. Correct the reservation before checking in.']);
                }
            }
            $openTime = $data['rate_tier'] === 'open';
            if ($openTime && $booking) {
                throw ValidationException::withMessages(['rate_tier' => 'Reservations require a fixed stay package.']);
            }
            $tier = $booking?->rate_tier ?? ($openTime ? '3h' : $data['rate_tier']);
            if ($tier === 'promo' && ! config('sedona.midnight_promo_enabled')) {
                throw ValidationException::withMessages(['rate_tier' => 'Midnight promo is temporarily unavailable. Select a regular stay package.']);
            }
            $checkedInAt = now();
            $this->stayRates->assertPromoArrival($tier, $checkedInAt);
            $checkout = $room->getCheckoutForTier($tier, $checkedInAt);
            $this->stayRates->assertReservationAvailable($room, $checkedInAt, $checkout, $booking?->id, $openTime);
            $guest = Guest::create([
                'name' => $booking?->guest_name ?? ($data['guest_name'] ?? 'Guest Room '.$room->number),
                'contact' => $booking?->guest_contact ?? ($data['guest_contact'] ?? 'WALKIN-'.uniqid()),
                'headcount' => $booking?->headcount ?? $data['headcount'],
                'is_senior' => $request->boolean('is_senior'), 'is_pwd' => $request->boolean('is_pwd'),
            ]);
            $folio = Folio::create([
                'room_id' => $room->id, 'guest_id' => $guest->id, 'user_id' => Auth::id(), 'booking_id' => $booking?->id,
                'transaction_id' => Folio::generateTransactionId(), 'rate_tier' => $tier, 'stay_mode' => $openTime ? 'open' : 'fixed',
                'checked_in_at' => $checkedInAt, 'expected_checkout_at' => $checkout,
                'status' => 'active', 'room_charge' => $room->getRateForTier($tier), 'pos_items' => [],
                'discount_type' => $guest->is_pwd ? 'PWD' : ($guest->is_senior ? 'SENIOR' : 'NONE'),
                'discount_schedule' => DiscountService::rates(),
                'discount_id_ref' => $guest->isEligibleForDiscount() ? trim($data['discount_id_ref']) : null,
                'senior_pwd_discount' => $guest->isEligibleForDiscount(), 'payment_method' => 'cash',
            ]);
            $folio->setRelation('room', $room);
            $folio->recalculate();
            $amenityWarning = $this->inventory->issueGuestHygieneKit($folio);
            $room->update(['status' => 'occupied']);
            $booking?->update(['status' => 'checked_in']);
            $this->audit->record($request, 'folio.checked_in', $folio, ['room_id' => $room->id, 'rate_tier' => $tier, 'stay_mode' => $folio->stay_mode,
                'checked_in_at' => $checkedInAt->toIso8601String(), 'transaction_id' => $folio->transaction_id,
                'complimentary_hygiene_kits' => 1, 'amenity_warning' => $amenityWarning]);

            return ['folio_id' => $folio->id, 'amenity_warning' => $amenityWarning];
        };
        if ($request->filled('operation_key')) {
            $result = $this->posting->execute($request, 'folio.checkin', (int) $data['room_id'], $data, $checkIn);
        } else {
            $result = DB::transaction($checkIn, 3);
        }

        $response = redirect()->route('dashboard')->with('success', 'Guest checked in successfully.');
        if (! empty($result['amenity_warning'])) {
            $response->with('info', $result['amenity_warning']);
        }

        return $response;
    }

    public function showCheckout(Request $request, Folio $folio, ShiftAccessService $shiftAccess): View
    {
        abort_unless($folio->isActive(), 409, 'This folio has already been settled.');

        return view('checkout.process', $this->viewData($folio) + [
            'allOccupied' => Room::where('status', 'occupied')->with('activeFolio.guest')->orderByNumber()->get(),
            'drawerStatus' => $shiftAccess->paymentStatus($request->user()),
        ]);
    }

    public function processCheckout(Request $request, Folio $folio): RedirectResponse
    {
        $data = $request->validate($this->draftRules());
        $this->posting->execute($request, 'checkout', $folio->id, $data, function () use ($request, $data, $folio): array {
            [$locked] = $this->lockActive($folio);
            $this->applyDraft($locked, $request, $data);
            foreach (['cash_tendered', 'gcash_amount'] as $field) {
                if ($request->has($field) && $request->input($field) === null) {
                    $locked->{$field} = 0;
                }
            }
            if ($request->has('gcash_reference') && ! $request->filled('gcash_reference')) {
                $locked->gcash_reference = null;
            }
            $bill = app(BillingService::class)->calculate($locked);
            $balance = BillingService::cents($bill['finalBalanceToPay']);
            $method = $locked->payment_method ?? 'cash';
            $cash = BillingService::cents($locked->cash_tendered);
            $gcash = BillingService::cents($locked->gcash_amount);
            if ($method === 'cash') {
                $gcash = 0;
            } elseif ($method === 'gcash') {
                $cash = 0;
            }
            if ($cash + $gcash < $balance || $gcash > $balance) {
                throw ValidationException::withMessages(['cash_tendered' => 'Actual payment must cover the bill; GCash cannot exceed the balance.']);
            }
            if ($gcash > 0 && ! trim((string) $locked->gcash_reference)) {
                throw ValidationException::withMessages(['gcash_reference' => 'A GCash reference is required.']);
            }
            $change = max(0, $cash + $gcash - $balance);
            $locked->fill($bill['attributes']);
            $locked->deposit_status = $bill['deposit'] > 0 ? ($bill['applyDeposit'] ? 'applied_to_bill' : 'not_applied') : 'none';
            $locked->fill([
                'checked_out_at' => $bill['checkOutTime'], 'checked_out_by' => Auth::id(), 'status' => 'checked_out',
                'cash_tendered' => $cash / 100, 'gcash_amount' => $gcash / 100, 'change_due' => $change / 100,
                'force_checkout' => false, 'force_reason' => null, 'force_by' => null, 'loss_slip_reference' => null,
            ]);
            $locked->save();
            $locked->issueReceipt();
            $this->postEntry($locked, 'settlement', ($cash - $change) / 100, $gcash / 100, $request);
            $locked->room->update(['status' => 'available']);
            if (! empty($data['customer_name'])) {
                $locked->guest()->update(['name' => $data['customer_name']]);
            }

            return ['folio_id' => $locked->id];
        });

        return redirect()->route('dashboard')->with('success', 'Guest checked out and payment recorded.')->with('settled_folio_id', $folio->id);
    }

    public function extendStay(Request $request, Folio $folio): RedirectResponse
    {
        $data = $request->validate(['hours' => 'required|integer|min:1|max:8760']);
        $extraHours = (int) $data['hours'];
        $this->posting->execute($request, 'folio.extend', $folio->id, $data, function () use ($request, $folio, $extraHours): array {
            [$locked] = $this->lockActive($folio);
            if ($locked->isOpenTime()) {
                throw ValidationException::withMessages(['hours' => 'Open Time already bills additional hours automatically at ₱130 per hour. Use package conversion for a 12-hour or 24-hour stay.']);
            }
            $checkout = $locked->room->getCheckoutForTier($locked->rate_tier, $locked->checked_in_at, (int) $locked->extra_hours + $extraHours);
            if (! $checkout->gt($locked->expected_checkout_at)) {
                throw ValidationException::withMessages(['hours' => 'An extension must move the current checkout time forward. Reload this stay.']);
            }
            $this->stayRates->assertReservationAvailable($locked->room, now(), $checkout, $locked->booking_id);
            $before = $locked->only(['extra_hours', 'expected_checkout_at', 'net_total']);
            $locked->extra_hours += $extraHours;
            $locked->expected_checkout_at = $checkout;
            $locked->recalculate();
            $this->audit->record($request, 'folio.extended', $locked, ['before' => $before,
                'after' => $locked->only(array_keys($before)), 'operation_key' => $request->input('operation_key')]);

            return ['folio_id' => $locked->id];
        });

        return redirect()->route('dashboard')->with('success', 'Stay extended.');
    }

    public function convertPackage(Request $request, Folio $folio): RedirectResponse
    {
        $data = $request->validate(['rate_tier' => 'required|in:12h,24h', 'reason' => 'required|string|max:500']);
        $this->posting->execute($request, 'folio.convert', $folio->id, $data, function () use ($request, $folio, $data): array {
            [$locked, $room] = $this->lockActive($folio);
            $locked->setRelation('room', $room);
            $this->stayRates->convert($locked, $data['rate_tier'], $request);

            return ['folio_id' => $locked->id];
        });

        return redirect()->route('dashboard')->with('success', 'Stay package converted. The room rate, discount, checkout time and alarms have been updated.');
    }

    public function cancelStay(Request $request, Folio $folio, StayCancellationService $cancellations): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);
        $cancellations->cancel($request, $folio, $data);

        return redirect()->route('dashboard')->with('success', 'Stay '.$folio->transaction_id.' cancelled. The room is available again.');
    }

    public function applyDiscount(Request $request, Folio $folio): RedirectResponse
    {
        $data = $request->validate(['discount_type' => 'required|in:senior,pwd,dc,none', 'discount_id_ref' => 'nullable|string|max:100']);
        DB::transaction(function () use ($request, $folio, $data): void {
            [$locked] = $this->lockActive($folio);
            $before = $locked->only(['discount_type', 'discount_id_ref', 'discount_amount', 'senior_pwd_discount', 'net_total']);
            $this->applyDraft($locked, $request, $data);
            $locked->recalculate();
            $after = $locked->only(array_keys($before));
            if ($before != $after) {
                $this->audit->record($request, 'folio.discount_applied', $locked, ['before' => $before, 'after' => $after]);
            }
        });

        return redirect()->route('dashboard')->with('success', 'Discount applied.');
    }

    public function updateDeposit(Request $request, Folio $folio): JsonResponse|RedirectResponse
    {
        $data = $request->validate($this->draftRules());
        $locked = DB::transaction(function () use ($request, $data, $folio): Folio {
            [$locked] = $this->lockActive($folio);
            $before = $locked->only(['payment_method', 'cash_tendered', 'gcash_amount', 'gcash_reference', 'room_charge', 'discount_type', 'discount_id_ref', 'checked_in_at', 'deposit_status']);
            $this->applyDraft($locked, $request, $data);
            $bill = app(BillingService::class)->calculate($locked);
            $locked->fill($bill['attributes']);
            $cash = BillingService::cents($locked->cash_tendered);
            $gcash = $locked->payment_method === 'cash' ? 0 : BillingService::cents($locked->gcash_amount);
            $locked->change_due = max(0, $cash + $gcash - BillingService::cents($bill['finalBalanceToPay'])) / 100;
            $locked->save();
            $after = $locked->only(array_keys($before));
            if ($before != $after) {
                $this->audit->record($request, 'folio.draft_saved', $locked, ['before' => $before, 'after' => $after]);
            }

            return $locked;
        });
        if ($request->expectsJson()) {
            return response()->json(['success' => true] + $locked->only(['security_deposit', 'deposit_status', 'payment_method', 'cash_tendered', 'gcash_amount', 'gcash_reference', 'change_due', 'net_total', 'discount_type', 'discount_id_ref', 'discount_amount', 'checked_in_at', 'room_charge']));
        }

        return back()->with('success', 'Settlement draft saved. No money movement was recorded.');
    }

    public function collectDeposit(Request $request, Folio $folio): RedirectResponse
    {
        $data = $request->validate([
            'amount' => 'required|numeric|min:0.01|max:100000', 'payment_method' => 'required|in:cash,gcash',
            'gcash_reference' => 'nullable|string|max:50', 'shift_override_reason' => 'nullable|string|max:500',
        ]);
        $this->posting->execute($request, 'deposit', $folio->id, $data, function () use ($request, $data, $folio): array {
            [$locked] = $this->lockActive($folio);
            $amount = BillingService::cents($data['amount']);
            if ($amount + BillingService::cents($locked->security_deposit) > 10000000) {
                throw ValidationException::withMessages(['amount' => 'Total deposit cannot exceed 100,000.']);
            }
            $entry = $this->posting->post($request, $locked, 'deposit', $data['payment_method'] === 'cash' ? $amount : 0, $data['payment_method'] === 'gcash' ? $amount : 0, $data['gcash_reference'] ?? null);
            $locked->security_deposit = (BillingService::cents($locked->security_deposit) + $amount) / 100;
            $locked->deposit_payment_method = $data['payment_method'];
            $locked->deposit_collected_at = now();
            if ($locked->deposit_status === 'none') {
                $locked->deposit_status = 'applied_to_bill';
            }
            $locked->recalculate();

            return ['payment_entry_id' => $entry->id];
        });

        return redirect()->route('checkout.process', $folio)->with('success', 'Actual deposit received and recorded.');
    }

    public function transferRoom(Request $request, Folio $folio): RedirectResponse
    {
        $data = $request->validate(['target_room_id' => 'required|exists:rooms,id']);
        DB::transaction(function () use ($folio, $data, $request): void {
            $rooms = Room::whereIn('id', [$folio->room_id, $data['target_room_id']])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $locked = Folio::whereKey($folio->id)->lockForUpdate()->firstOrFail();
            $this->requireActive($locked);
            abort_unless($locked->room_id === $folio->room_id, 409, 'Room assignment changed. Reload the folio.');
            $target = $rooms[$data['target_room_id']];
            $this->requireAvailable($target);
            $oldRoom = $rooms[$locked->room_id];
            $this->stayRates->transfer($locked, $target, $request);
            $locked->orders()->update(['room_id' => $target->id]);
            $oldRoom->update(['status' => 'available']);
            $target->update(['status' => 'occupied']);
        });

        return redirect()->route('dashboard')->with('success', 'Guest transferred.');
    }

    public function requestForceCheckout(Request $request, Folio $folio): RedirectResponse
    {
        $data = $request->validate(['force_reason' => 'required|string|max:500']);
        $this->posting->execute($request, 'approval.write_off', $folio->id, $data, function () use ($request, $folio, $data): array {
            [$locked] = $this->lockActive($folio);
            $locked->update(['force_checkout' => true, 'force_reason' => $data['force_reason'], 'force_requested_by' => $request->user()->id]);
            $approval = app(ApprovalService::class)->request($request, 'write_off', $folio->id, ['amount' => app(BillingService::class)->calculate($locked)['finalBalanceToPay']], $data['force_reason']);

            return ['approval_id' => $approval->id];
        });

        return redirect()->route('approvals.index')->with('success', 'Write-off awaits independent review.');
    }

    public function approveForceCheckout(Request $request, Folio $folio): RedirectResponse
    {
        return redirect()->route('approvals.index')->with('success', 'Review the recorded write-off request, then execute its approved amount.');
    }

    public function showReceipt(Request $request, Folio $folio): View
    {
        abort_if($folio->status === 'voided', 409, 'Cancelled stays cannot issue settlement receipts.');

        return view('checkout.receipt', $this->viewData($folio, $request));
    }

    public function requestReceiptPrint(Request $request, Folio $folio): View
    {
        abort_unless($folio->status === 'checked_out' && $folio->checked_out_at !== null, 409, 'Settle the folio before issuing a receipt.');
        $result = $this->recordPrintRequest($request, $folio, 'receipt');

        return view('checkout.receipt', $this->viewData($folio) + ['receiptCopy' => $result['copy_number'], 'printRequested' => true]);
    }

    public function requestDepositPrint(Request $request, Folio $folio): View
    {
        abort_unless(BillingService::cents($folio->security_deposit) > 0, 409, 'Record a deposit before issuing its slip.');
        $result = $this->recordPrintRequest($request, $folio, 'deposit');

        return $this->showDepositSlip($request, $folio)->with(['receiptCopy' => $result['copy_number'], 'printRequested' => true]);
    }

    public function requestRefundPrint(Request $request, Folio $folio): View
    {
        abort_if($folio->isActive(), 409, 'Settle the folio before printing its refund summary.');
        $result = $this->recordPrintRequest($request, $folio, 'deposit_refund');

        return $this->showDepositRefund($request, $folio)->with(['receiptCopy' => $result['copy_number'], 'printRequested' => true]);
    }

    /** @return array<string, mixed> */
    private function recordPrintRequest(Request $request, Folio $folio, string $document): array
    {
        $payload = $document === 'billing' ? $request->except(['_token', 'operation_key']) : [];

        $created = false;
        $result = $this->posting->execute($request, $document.'.print', $folio->id, $payload, function () use ($request, $folio, $document, &$created): array {
            $locked = Folio::whereKey($folio->id)->lockForUpdate()->firstOrFail();
            $snapshot = null;
            if ($document === 'billing') {
                abort_if($locked->status === 'voided', 409, 'Cancelled stays cannot issue billing statements.');
                $snapshot = $this->billingSnapshot($this->viewData($locked, $request));
                if ($locked->billing_printed_at === null) {
                    $locked->billing_printed_at = now();
                    $locked->save();
                }
            }
            $copy = DB::table('audit_logs')->where('auditable_type', Folio::class)->where('auditable_id', $locked->id)->where('event', $document.'.print_requested')->count() + 1;
            $this->audit->record($request, $document.'.print_requested', $locked, ['copy_number' => $copy, 'operation_key' => $request->input('operation_key'), 'physical_print_confirmed' => false, 'snapshot' => $snapshot]);
            $created = true;

            return ['copy_number' => $copy, 'snapshot' => $snapshot];
        });

        return $result + ['new_print_request' => $created];
    }

    public function showBillingStatement(Request $request, Folio $folio): View
    {
        return view('checkout.billing', $this->viewData($folio, $request));
    }

    public function requestBillingPrint(Request $request, Folio $folio): View|RedirectResponse
    {
        try {
            $result = $this->recordPrintRequest($request, $folio, 'billing');

            if (empty($result['snapshot'])) {
                throw ValidationException::withMessages(['print' => 'This older print request has no saved bill snapshot. Use Print Statement / Billing again to record a new copy of the current bill.']);
            }
            $snapshot = $result['snapshot'];
            $printed = clone $folio;
            $printed->forceFill($snapshot['folio']);
            $room = clone $folio->room;
            $room->number = $snapshot['room_number'];
            $room->type = $snapshot['room_type'];
            $printed->setRelation('room', $room);
            $bill = $snapshot['billing'];
            foreach (['checkInTime', 'checkOutTime', 'expectedCheckoutAt'] as $field) {
                $bill[$field] = Carbon::parse($bill[$field]);
            }
            $bill['preparedAt'] = Carbon::parse($bill['preparedAt'] ?? $bill['checkOutTime']);

            return view('checkout.billing', $bill + ['folio' => $printed, 'receiptCopy' => $result['copy_number'], 'printRequested' => $result['new_print_request']]);
        } catch (ValidationException $exception) {
            return redirect()->route('folios.billing', $folio)->withErrors($exception->errors());
        }
    }

    /**
     * @param  array<string, mixed>  $bill
     * @return array<string, mixed>
     */
    private function billingSnapshot(array $bill): array
    {
        $folio = $bill['folio'];
        $attributes = $folio->only(['transaction_id', 'receipt_number', 'status', 'stay_mode', 'rate_tier', 'checked_in_at', 'expected_checkout_at', 'extra_hours', 'discount_type', 'discount_id_ref']);
        foreach (['checked_in_at', 'expected_checkout_at'] as $field) {
            $attributes[$field] = $folio->{$field}->toIso8601String();
        }
        $values = array_diff_key($bill, array_flip(['folio', 'room', 'guest', 'discountOptions']));
        $values['preparedAt'] = now()->toIso8601String();
        foreach (['checkInTime', 'checkOutTime', 'expectedCheckoutAt'] as $field) {
            $values[$field] = $bill[$field]->toIso8601String();
        }

        return ['version' => 1, 'room_id' => $folio->room_id, 'room_number' => $folio->room->number, 'room_type' => $folio->room->type,
            'stay_reference' => $folio->transaction_id, 'receipt_reference' => $folio->receiptReference(), 'folio' => $attributes, 'billing' => $values];
    }

    public function showDepositSlip(Request $request, Folio $folio): View
    {
        $folio->load(['room', 'guest', 'cashier']);

        return view('checkout.deposit_slip', ['folio' => $folio, 'depositAmount' => (float) $folio->security_deposit]);
    }

    public function showLossSlip(Folio $folio): View
    {
        abort_unless(! $folio->isActive() && $folio->force_by && $folio->loss_slip_reference, 409, 'Loss has not been approved.');
        $folio->load(['room', 'guest', 'cashier', 'forcedBy']);

        return view('checkout.loss_slip', compact('folio'));
    }

    public function showGatePass(Folio $folio): View
    {
        abort_unless($folio->status === 'checked_out' && $folio->checked_out_at !== null, 409, 'Settle the folio before issuing a gate pass.');
        $folio->load(['room', 'guest', 'cashier', 'checkedOutBy']);
        $ticketNo = 'GP-RM'.($folio->room->number ?? '00').'-'.$folio->checked_out_at->format('mdHi');

        return view('checkout.gate_pass', compact('folio', 'ticketNo'));
    }

    public function showDepositRefund(Request $request, Folio $folio): View
    {
        $folio->load(['room', 'guest', 'cashier', 'checkedOutBy']);
        $bill = app(BillingService::class)->calculate($folio);
        $refundOperator = $folio->paymentEntries()->where('kind', 'deposit_refund')->latest('posted_at')->latest('id')->with('user')->first()?->user;

        return view('checkout.deposit_refund_slip', ['folio' => $folio, 'depositAmount' => $bill['deposit'], 'deductions' => $bill['depositApplied'], 'refundAmount' => (float) $folio->deposit_refunded_amount, 'remainingDeposit' => $bill['remainingDeposit'], 'refundOperator' => $refundOperator]);
    }

    public function refundDeposit(Request $request, Folio $folio): RedirectResponse
    {
        $data = $request->validate(['amount' => 'required|numeric|min:0.01', 'payment_method' => 'required|in:cash,gcash', 'gcash_reference' => 'nullable|string|max:50', 'shift_override_reason' => 'nullable|string|max:500', 'reason' => 'nullable|string|max:1000', 'evidence_reference' => 'nullable|string|max:255']);
        $this->posting->execute($request, 'approval.refund', $folio->id, $data, function () use ($folio, $data, $request): array {
            $approval = app(ApprovalService::class)->request($request, 'deposit_refund', $folio->id, $data, $request->input('reason', 'Return remaining guest deposit'), $request->input('evidence_reference'));

            return ['approval_id' => $approval->id];
        });

        return redirect()->route('approvals.index')->with('success', 'Refund awaits independent review. No money has been paid out yet.');
    }

    private function requireAvailable(Room $room): void
    {
        if ($room->is_staff_quarters || $room->status !== 'available' || $room->folios()->where('status', 'active')->exists()) {
            throw ValidationException::withMessages(['room_id' => 'This room is unavailable for guest occupancy.']);
        }
    }

    private function requireActive(Folio $folio): void
    {
        abort_unless($folio->isActive(), 409, 'This folio has already been settled.');
        abort_if($folio->room->is_staff_quarters, 409, 'Employee quarters cannot use guest checkout actions.');
    }

    private function lockActive(Folio $folio): array
    {
        $room = Room::whereKey($folio->room_id)->lockForUpdate()->firstOrFail();
        $locked = Folio::whereKey($folio->id)->lockForUpdate()->firstOrFail();
        abort_unless($locked->room_id === $room->id, 409, 'Room assignment changed. Reload the folio.');
        $locked->setRelation('room', $room);
        $this->requireActive($locked);

        return [$locked, $room];
    }

    private function draftRules(): array
    {
        return [
            'payment_method' => 'nullable|in:cash,gcash,split', 'cash_tendered' => 'nullable|numeric|min:0|max:99999999.99',
            'gcash_amount' => 'nullable|numeric|min:0|max:99999999.99', 'gcash_reference' => 'nullable|string|max:50',
            'customer_name' => 'nullable|string|max:150', 'security_deposit' => 'nullable|numeric|min:0|max:100000',
            'deposit_payment_method' => 'nullable|in:cash,gcash', 'apply_deposit' => 'nullable|boolean',
            'discount_type' => 'nullable|in:none,senior,pwd,dc,NONE,SENIOR,PWD,DC', 'discount_id_ref' => 'nullable|string|max:100',
            'checked_in_at' => 'nullable|date|before_or_equal:now', 'room_charge' => 'nullable|numeric|min:0|max:99999999.99',
            'shift_override_reason' => 'nullable|string|max:500',
        ];
    }

    /** @param array<string, mixed> $data */
    private function assertApprovedPricing(Folio $folio, array $data): void
    {
        if (isset($data['room_charge']) && BillingService::cents($data['room_charge']) !== BillingService::cents($folio->room_charge)) {
            throw ValidationException::withMessages(['room_charge' => 'Use Approvals to request and execute a rate override.']);
        }
        if (isset($data['discount_type']) && strtoupper($data['discount_type']) !== strtoupper($folio->discount_type ?? 'NONE')) {
            if ($folio->discount_override_amount !== null) {
                throw ValidationException::withMessages(['discount_type' => 'Custom discount changes require admin/owner approval.']);
            }
        }
    }

    private function applyDraft(Folio $folio, Request $request, array $data): void
    {
        $this->assertApprovedPricing($folio, $data);
        if (isset($data['checked_in_at']) && ! Carbon::parse($data['checked_in_at'])->equalTo(strlen($data['checked_in_at']) <= 16 ? $folio->checked_in_at->copy()->startOfMinute() : $folio->checked_in_at)) {
            throw ValidationException::withMessages(['checked_in_at' => 'Save the corrected check-in time using Save check-in time on the checkout screen, then reload any other open checkout or billing tabs.']);
        }
        $discountType = DiscountService::normalizeDiscountType($data['discount_type'] ?? $folio->discount_type);
        if (! $discountType && ! isset($data['discount_type']) && $folio->senior_pwd_discount) {
            $discountType = 'SENIOR';
        }
        $discountReference = array_key_exists('discount_id_ref', $data) ? trim((string) $data['discount_id_ref']) : trim((string) $folio->discount_id_ref);
        if (in_array($discountType, ['SENIOR', 'PWD'], true) && $discountReference === '') {
            throw ValidationException::withMessages(['discount_id_ref' => 'Record the statutory discount ID reference.']);
        }
        foreach (['payment_method', 'cash_tendered', 'gcash_amount', 'gcash_reference', 'room_charge', 'discount_id_ref'] as $field) {
            if (isset($data[$field])) {
                $folio->{$field} = $data[$field];
            } elseif (array_key_exists($field, $data) && in_array($field, ['cash_tendered', 'gcash_amount', 'gcash_reference'])) {
                $folio->{$field} = $field === 'gcash_reference' ? null : 0;
            }
        }
        if (isset($data['discount_type'])) {
            $folio->discount_type = strtoupper($data['discount_type']);
            $folio->senior_pwd_discount = in_array($folio->discount_type, ['SENIOR', 'PWD']);
            if ($folio->discount_type === 'NONE') {
                $folio->discount_id_ref = null;
            }
        }
        if ($request->has('apply_deposit')) {
            $folio->deposit_status = $request->boolean('apply_deposit') ? 'applied_to_bill' : 'not_applied';
        }
    }

    private function postEntry(Folio $folio, string $kind, float $cash, float $gcash, Request $request): void
    {
        $this->posting->post($request, $folio, $kind, BillingService::cents($cash), BillingService::cents($gcash), $folio->gcash_reference);
    }

    private function viewData(Folio $folio, ?Request $request = null): array
    {
        $folio->load(['room', 'guest', 'orders.items', 'cashier', 'checkedOutBy']);
        $preview = clone $folio;
        $at = null;
        if ($preview->isActive() && $request) {
            $data = $request->validate($this->draftRules() + ['deposit' => 'nullable|numeric|min:0', 'checked_out_at' => 'nullable|date']);
            $this->assertApprovedPricing($preview, $data);
            foreach (['room_charge', 'discount_type', 'discount_id_ref', 'payment_method', 'cash_tendered', 'gcash_amount', 'gcash_reference'] as $field) {
                if (isset($data[$field])) {
                    $preview->{$field} = $data[$field];
                }
            }
            if (isset($data['discount_type'])) {
                $preview->senior_pwd_discount = in_array(strtoupper($data['discount_type']), ['SENIOR', 'PWD']);
            }
            if ($request->has('apply_deposit')) {
                $preview->deposit_status = $request->boolean('apply_deposit') ? 'applied_to_bill' : 'not_applied';
            }
            $preview->expected_checkout_at = $preview->room->getCheckoutForTier($preview->rate_tier, $preview->checked_in_at, (int) $preview->extra_hours);
            $at = isset($data['checked_out_at']) ? Carbon::parse($data['checked_out_at']) : null;
        }
        $bill = app(BillingService::class)->calculate($preview, $at);
        $preview->discount_type = $bill['discountType'];
        $change = (float) $preview->change_due;
        if ($preview->isActive()) {
            $cash = $preview->payment_method === 'gcash' ? 0 : BillingService::cents($preview->cash_tendered);
            $gcash = $preview->payment_method === 'cash' ? 0 : BillingService::cents($preview->gcash_amount);
            $change = max(0, $cash + $gcash - BillingService::cents($bill['finalBalanceToPay'])) / 100;
            $preview->change_due = $change;
        }

        return $bill + [
            'folio' => $preview, 'room' => $preview->room, 'guest' => $preview->guest,
            'discountOptions' => DiscountService::getOptionsForFolio($preview),
            'paymentMethod' => $preview->payment_method, 'cashTendered' => (float) $preview->cash_tendered,
            'gcashAmount' => (float) $preview->gcash_amount, 'gcashRef' => $preview->gcash_reference, 'changeDue' => $change,
        ];
    }
}
