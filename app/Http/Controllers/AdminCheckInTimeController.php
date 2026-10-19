<?php

namespace App\Http\Controllers;

use App\Models\Folio;
use App\Models\Room;
use App\Services\AuditService;
use App\Services\FinancialPostingService;
use App\Services\StayRateService;
use Carbon\Carbon;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AdminCheckInTimeController extends Controller
{
    public function __construct(private FinancialPostingService $posting, private AuditService $audit, private StayRateService $stayRates) {}

    public function index(Request $request): View
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'owner']), 403);
        $selectedId = $request->integer('folio_id');
        $rooms = Room::where('is_staff_quarters', false)->whereHas('activeFolio')
            ->when($selectedId, fn ($query) => $query->whereHas('activeFolio', fn ($folioQuery) => $folioQuery->whereKey($selectedId)))
            ->with(['activeFolio.guest', 'activeFolio.cashier'])->orderByNumber()->get();
        $folios = $rooms->map(fn (Room $room): Folio => $room->activeFolio);
        $history = DB::table('audit_logs')->leftJoin('users', 'users.id', '=', 'audit_logs.user_id')
            ->where('event', 'folio.checkin_time_corrected')->where('auditable_type', Folio::class)
            ->when($selectedId, fn ($query) => $query->where('auditable_id', $selectedId))
            ->orderByDesc('audit_logs.id')->select('audit_logs.*', 'users.name as operator_name')
            ->limit(20)->get()->map(function ($entry): array {
                $values = json_decode($entry->new_values, true);

                return [
                    'folio_id' => $entry->auditable_id, 'reference' => $values['transaction_id'] ?? '',
                    'operator' => $entry->operator_name, 'recorded_at' => $entry->created_at,
                    'before' => Carbon::parse($values['before']['checked_in_at'])->setTimezone(config('app.timezone'))->format('m/d/Y h:i:s A'),
                    'after' => Carbon::parse($values['after']['checked_in_at'])->setTimezone(config('app.timezone'))->format('m/d/Y h:i:s A'),
                    'reason' => $values['reason'] ?? '',
                ];
            });

        return view('admin.check-in-times', compact('folios', 'history', 'selectedId'));
    }

    public function update(Request $request, Folio $folio): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['admin', 'owner']), 403);
        $this->saveCorrection($request, $folio);

        return redirect()->route('admin.check-in-times.index', ['folio_id' => $folio->id])
            ->with('success', 'RA declaration time saved. Refresh any open Front Desk or checkout screen to load the corrected time.');
    }

    public function updateFromCheckout(Request $request, Folio $folio): RedirectResponse
    {
        abort_unless($request->user()->hasAnyRole(['cashier', 'front_desk', 'admin', 'owner']), 403);
        $data = $request->validate(['actual_checked_in_at' => 'required|date_format:Y-m-d\TH:i']);
        $request->merge(['checked_in_at' => Carbon::parse($data['actual_checked_in_at'], 'Asia/Manila')->toIso8601String()]);
        $this->saveCorrection($request, $folio);

        return redirect()->route('checkout.process', $folio)
            ->with('success', 'Check-in time saved. The checkout schedule, stay duration and bill have been recalculated.');
    }

    private function saveCorrection(Request $request, Folio $folio): void
    {
        $data = $request->validate([
            'checked_in_at' => 'required|date|before_or_equal:now',
            'expected_checked_in_at' => 'required|date',
            'reason' => 'required|string|max:500',
        ]);
        $this->posting->execute($request, 'folio.checkin_time', $folio->id, $data, function () use ($request, $folio, $data): array {
            $room = Room::whereKey($folio->room_id)->lockForUpdate()->firstOrFail();
            $locked = Folio::whereKey($folio->id)->lockForUpdate()->firstOrFail();
            $locked->assertStayTimeEditable($request->user());
            abort_unless($locked->isActive() && ! $room->is_staff_quarters, 409, 'Only active guest stays can have their check-in time corrected.');
            abort_unless((int) $locked->room_id === $room->id, 409, 'Room assignment changed. Reload this stay.');
            abort_unless($locked->checked_in_at->equalTo(Carbon::parse($data['expected_checked_in_at'])), 409, 'This check-in time has changed. Reload before correcting it.');
            $corrected = Carbon::parse($data['checked_in_at'])->setTimezone(config('app.timezone'));
            if ($corrected->equalTo($locked->checked_in_at)) {
                return ['folio_id' => $locked->id];
            }
            $arrivalRoomId = $room->id;
            $arrivalRoomDeparture = now();
            $firstTransfer = DB::table('audit_logs')->where('auditable_type', Folio::class)->where('auditable_id', $locked->id)
                ->where('event', 'folio.room_transferred')->orderBy('id')->first(['new_values', 'created_at']);
            if ($firstTransfer) {
                $arrivalRoomId = (int) data_get(json_decode($firstTransfer->new_values, true), 'before.room_id');
                if ($arrivalRoomId < 1) {
                    throw ValidationException::withMessages(['checked_in_at' => 'The original room could not be verified from the transfer history. Ask an admin or owner to review this stay.']);
                }
                $arrivalRoomDeparture = Carbon::parse($firstTransfer->created_at);
                if ($corrected->gt($arrivalRoomDeparture)) {
                    throw ValidationException::withMessages(['checked_in_at' => 'Check-in time cannot be later than the first room transfer.']);
                }
            }
            if (Folio::where('room_id', $arrivalRoomId)->whereKeyNot($locked->id)->where('status', 'checked_out')
                ->where('checked_in_at', '<', $arrivalRoomDeparture)->where('checked_out_at', '>', $corrected)->exists()) {
                throw ValidationException::withMessages(['checked_in_at' => 'This declaration time overlaps another recorded stay in the room.']);
            }
            $this->stayRates->assertPromoArrival($locked->rate_tier, $corrected, 'checked_in_at');
            $checkout = $room->getCheckoutForTier($locked->rate_tier, $corrected, (int) $locked->extra_hours);
            $this->stayRates->assertReservationAvailable($room, now(), $checkout, $locked->booking_id, $locked->isOpenTime());
            $before = $this->snapshot($locked);
            $locked->setRelation('room', $room);
            $locked->checked_in_at = $corrected;
            $locked->expected_checkout_at = $checkout;
            $locked->recalculate();
            $this->audit->record($request, 'folio.checkin_time_corrected', $locked, [
                'transaction_id' => $locked->transaction_id, 'room_id' => $room->id,
                'before' => $before, 'after' => $this->snapshot($locked), 'reason' => $data['reason'],
                'recorded_at' => now()->toIso8601String(), 'operation_key' => $request->input('operation_key'),
            ]);

            return ['folio_id' => $locked->id];
        });
    }

    /** @return array{checked_in_at: string, expected_checkout_at: string, net_total: string} */
    private function snapshot(Folio $folio): array
    {
        return [
            'checked_in_at' => $folio->checked_in_at->toIso8601String(),
            'expected_checkout_at' => $folio->expected_checkout_at->toIso8601String(),
            'net_total' => $folio->net_total,
        ];
    }
}
