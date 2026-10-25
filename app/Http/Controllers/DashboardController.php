<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Models\Booking;
use App\Models\Folio;
use App\Models\Order;
use App\Models\PaymentEntry;
use App\Models\PosItem;
use App\Models\Room;
use App\Models\Shift;
use App\Services\DiscountService;
use App\Services\ShiftReportService;
use App\Services\StayRateService;
use Illuminate\Http\Request;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(Request $request)
    {
        if (auth()->check()) {
            if (auth()->user()->hasRole('kitchen')) {
                return redirect()->route('kitchen.view');
            }
            if (auth()->user()->hasRole('customer_display')) {
                return redirect()->route('kiosk.view');
            }
        }

        $selectedFloor = $request->query('floor', 'all');
        $selectedStatus = $request->query('status', 'all');

        $shiftActivity = null;
        if ($request->user()->hasAnyRole(['admin', 'owner'])) {
            $shiftFilters = $request->validate(['shift_date' => 'nullable|date_format:Y-m-d']);
            $shiftActivity = app(ShiftReportService::class)->activity($shiftFilters['shift_date'] ?? null);
        }

        $query = Room::with(['activeFolio.guest', 'activeFolio.orders.items']);

        if ($selectedFloor !== 'all') {
            $query->where('floor', (int) $selectedFloor);
        }

        if ($selectedStatus !== 'all') {
            $query->where('status', $selectedStatus);
        }

        // Ensure Staff Quarters (Room 12) has an initialized staff folio for F&B add-ons
        $staffRoom = Room::where('is_staff_quarters', true)->first();
        if ($staffRoom && ! $staffRoom->activeFolio) {
            $staffRoom->getOrCreateStaffFolio();
        }

        $rooms = $query->orderByNumber()->get();
        $checkInOrder = fn (Room $room): int => $room->activeFolio?->checked_in_at?->getTimestamp() ?? PHP_INT_MAX;
        $occupiedRoomsList = $rooms->where('status', 'occupied')->sortBy($checkInOrder)->values();

        // Overall stats (3-state room lifecycle: available, occupied, maintenance)
        $allRooms = Room::all();
        $totalRooms = $allRooms->where('is_staff_quarters', false)->count();
        $availableRooms = $allRooms->where('status', 'available')->where('is_staff_quarters', false)->count();
        $occupiedRooms = $allRooms->where('is_staff_quarters', false)->where('status', 'occupied')->count();
        $maintenanceRooms = $allRooms->where('status', 'maintenance')->count();
        $occupancyRate = $totalRooms > 0 ? round(($occupiedRooms / $totalRooms) * 100, 1) : 0;

        // Active shift
        $activeShift = Shift::whereNull('closed_at')->latest()->first();

        // Available rooms for fast transfer modal
        $availableRoomsList = Room::where('status', 'available')
            ->where('is_staff_quarters', false)
            ->orderByNumber()
            ->get();

        // Pending force checkout requests
        $pendingForceCheckouts = Folio::with(['room', 'guest', 'cashier'])
            ->whereIn('id', ApprovalRequest::where('kind', 'write_off')->where('source_type', Folio::class)->whereIn('status', ['pending', 'approved'])->select('source_id'))
            ->where('status', 'active')
            ->get();

        // Active folios
        $roomNumberCast = in_array(Room::query()->getConnection()->getDriverName(), ['mysql', 'mariadb'], true) ? 'UNSIGNED' : 'INTEGER';
        $activeFolios = Folio::with(['room', 'guest', 'orders'])->select('folios.*')
            ->join('rooms', 'rooms.id', '=', 'folios.room_id')
            ->where('folios.status', 'active')
            ->orderByRaw('CAST(rooms.number AS '.$roomNumberCast.')')->orderBy('rooms.number')->get();

        // Upcoming bookings for today/tomorrow
        $upcomingBookings = Booking::with('room')
            ->where('status', 'scheduled')
            ->where('arrival_at', '>=', now()->startOfDay())
            ->where('arrival_at', '<=', now()->addDays(2)->endOfDay())
            ->orderBy('arrival_at')
            ->take(6)
            ->get();

        // Pos Items for quick order modal
        $posItems = PosItem::where('is_available', true)->orderBy('category')->orderBy('name')->get();

        // ── Executive apartment board (owner/admin/manager) ──────────
        // Full room collection with folio relations; counts always computed
        // from the unfiltered set so sidebar numbers stay truthful.
        $boardAll = Room::with(['activeFolio.guest'])->orderByNumber()->get();
        $boardTotal = $boardAll->count();
        $boardOcc = $boardAll->where('status', 'occupied')->count();
        $boardAvail = $boardAll->where('status', 'available')->where('is_staff_quarters', false)->count();
        $boardLate = 0;
        $boardAlmost = 0;
        foreach ($boardAll as $br) {
            $bf = $br->activeFolio;
            if ($br->status === 'occupied' && $bf && ! $bf->isOpenTime() && $bf->expected_checkout_at) {
                if (now()->gt($bf->expected_checkout_at)) {
                    $boardLate++;
                } elseif (now()->diffInMinutes($bf->expected_checkout_at, false) <= 30) {
                    $boardAlmost++;
                }
            }
        }
        $boardRate = $boardTotal > 0 ? (int) round($boardOcc / $boardTotal * 100) : 0;
        $boardCounts = [
            'all' => $boardTotal,
            'available' => $boardAvail,
            'occupied' => $boardOcc,
            'late' => $boardLate,
            'almost' => $boardAlmost,
            'maintenance' => $boardAll->where('status', 'maintenance')->count(),
        ];

        // Board filters (collection-level; tables view ignores these params).
        $bstatus = $request->query('bstatus', 'all');
        $btier = $request->query('btier', 'all');
        $boardRooms = $boardAll;
        if (in_array($bstatus, ['available', 'occupied', 'maintenance'], true)) {
            $boardRooms = $boardRooms->where('status', $bstatus);
            if ($bstatus === 'available') {
                $boardRooms = $boardRooms->where('is_staff_quarters', false);
            }
        } elseif ($bstatus === 'late') {
            $boardRooms = $boardRooms->filter(fn ($r) => $r->status === 'occupied' && $r->activeFolio && ! $r->activeFolio->isOpenTime() && $r->activeFolio->expected_checkout_at && now()->gt($r->activeFolio->expected_checkout_at));
        } elseif ($bstatus === 'almost') {
            $boardRooms = $boardRooms->filter(fn ($r) => $r->status === 'occupied' && $r->activeFolio && ! $r->activeFolio->isOpenTime() && $r->activeFolio->expected_checkout_at && now()->lte($r->activeFolio->expected_checkout_at) && now()->diffInMinutes($r->activeFolio->expected_checkout_at, false) <= 30);
        }
        if (in_array($btier, ['vip', 'premium', 'classic'], true)) {
            $boardRooms = $boardRooms->filter(fn ($r) => $this->roomTierKey($r) === $btier);
        }
        if (in_array($bstatus, ['occupied', 'late', 'almost'], true)) {
            $boardRooms = $boardRooms->sortBy($checkInOrder)->values();
        }

        // Notification items for the executive tab bar dropdown.
        $lowStockCount = PosItem::where('is_tracked', true)->whereColumn('stock_quantity', '<=', 'reorder_level')->count();
        $notifItems = array_values(array_filter([
            count($pendingForceCheckouts) > 0 && $request->user()->can('review-financial') ? ['count' => count($pendingForceCheckouts), 'label' => 'pending force checkout approval(s)', 'url' => route('approvals.index')] : null,
            $boardLate > 0 ? ['count' => $boardLate, 'label' => 'late checkout(s) on the board', 'url' => route('dashboard', ['bstatus' => 'late'])] : null,
            $lowStockCount > 0 ? ['count' => $lowStockCount, 'label' => 'item(s) at or below reorder level', 'url' => route('inventory.index')] : null,
        ]));
        $notifCount = array_sum(array_column($notifItems, 'count'));

        // Personal shift performance (cashier scope: own folios/orders within active shift window).
        // Admins keep full BI in Reports; cashiers must never see global sales.
        $myShiftRevenue = 0.0;
        $myCheckIns = 0;
        $myOrdersCount = 0;
        $myShiftLabel = 'No open shift';

        if (auth()->check() && auth()->user()->hasAnyRole(['cashier', 'front_desk'])) {
            $myId = auth()->id();
            $ownsDrawer = $activeShift && $activeShift->opened_by === $myId;
            $sharesDrawer = $activeShift && config('sedona.continuous_shift_enabled') && $request->user()->can('operate-shift', $activeShift);
            $usesDrawer = $ownsDrawer || $sharesDrawer;
            $hotelTime = now()->setTimezone('Asia/Manila');
            $reportShift = $hotelTime->format('H:i') >= '06:30' && $hotelTime->format('H:i') < '18:30' ? 'day' : 'night';
            $myPeriod = app(ShiftReportService::class)->period(null, $reportShift);
            $myShiftStart = $myPeriod['start']->setTimezone(config('app.timezone'));
            if ($usesDrawer && $activeShift->opened_at->greaterThan($myShiftStart)) {
                $myShiftStart = $activeShift->opened_at;
            }
            $myShiftLabel = $myPeriod['label'].' · '.$myPeriod['start']->format('m/d h:i A').'–'.$myPeriod['end']->format('m/d h:i A');

            $myCheckIns = Folio::where('user_id', $myId)
                ->where('checked_in_at', '>=', $myShiftStart)
                ->count();

            $myFolioRevenue = (float) Folio::where(function ($query) use ($myId): void {
                $query->where('checked_out_by', $myId)->orWhere(function ($legacy) use ($myId): void {
                    $legacy->whereNull('checked_out_by')->where('user_id', $myId);
                });
            })
                ->where('status', 'checked_out')->where('force_checkout', false)
                ->whereBetween('checked_out_at', [$myShiftStart, now()])
                ->get()->sum(fn (Folio $folio): float => (float) $folio->gross_total - (float) $folio->discount_amount);

            $myPosRevenue = (float) Order::where('user_id', $myId)
                ->where('type', 'walkin_pos')
                ->where('created_at', '>=', $myShiftStart)
                ->sum('total');

            $myOrdersCount = Order::where('user_id', $myId)
                ->where('created_at', '>=', $myShiftStart)
                ->count();

            $myRecordedReturns = -(float) PaymentEntry::where('user_id', $myId)->where('kind', 'pos_refund')
                ->whereBetween('posted_at', [$myShiftStart, now()])->get()
                ->sum(fn (PaymentEntry $entry): float => (float) $entry->cash_amount + (float) $entry->gcash_amount);
            $myShiftRevenue = round($myFolioRevenue + $myPosRevenue - $myRecordedReturns, 2);
        }

        // Owner/admin/manager get the apartment board; cashiers keep the tables.
        $boardView = auth()->check() && auth()->user()->hasAnyRole(['owner', 'admin', 'manager']);
        $folioDiscountOptions = $activeFolios->mapWithKeys(fn (Folio $folio): array => [$folio->id => DiscountService::getOptionsForFolio($folio)])->all();
        $stayRates = app(StayRateService::class);
        $folioExtensionOptions = $activeFolios->mapWithKeys(fn (Folio $folio): array => [$folio->id => [
            'extendUrl' => route('folios.extend', $folio),
            'openTime' => $folio->isOpenTime(),
            'convertUrl' => route('folios.convert', $folio),
            'canConvert' => collect(['12h', '24h'])->contains(fn (string $tier): bool => $stayRates->canConvert($folio, $tier)),
            'conversions' => collect(['12h', '24h'])->filter(fn (string $tier): bool => $stayRates->canConvert($folio, $tier))
                ->mapWithKeys(fn (string $tier): array => [$tier => ['rate' => $folio->room->getRateForTier($tier), 'checkout' => $folio->room->getCheckoutForTier($tier, $folio->checked_in_at)->format('m/d/Y h:i A')]])->all(),
            'currentTier' => $folio->rate_tier,
            'currentCheckout' => $folio->isOpenTime() ? 'Open — bills until checkout' : $folio->expected_checkout_at?->format('m/d/Y h:i A'),
            'conversionRate' => $folio->room->getRateForTier('24h'),
            'conversionCheckout' => $folio->checked_in_at->copy()->addHours(24)->format('m/d/Y h:i A'),
        ]])->all();

        return view($boardView ? 'dashboard.board' : 'dashboard.index', compact(
            'rooms',
            'shiftActivity',
            'folioDiscountOptions',
            'folioExtensionOptions',
            'totalRooms',
            'availableRooms',
            'occupiedRooms',
            'maintenanceRooms',
            'occupancyRate',
            'activeShift',
            'activeFolios',
            'upcomingBookings',
            'posItems',
            'availableRoomsList',
            'occupiedRoomsList',
            'pendingForceCheckouts',
            'selectedFloor',
            'selectedStatus',
            'myShiftRevenue',
            'myCheckIns',
            'myOrdersCount',
            'myShiftLabel',
            'boardRooms',
            'boardTotal',
            'boardCounts',
            'boardRate',
            'bstatus',
            'btier',
            'notifCount',
            'notifItems'
        ));
    }

    /**
     * Canonical room tier key derived from the room type label.
     */
    private function roomTierKey(Room $room): string
    {
        $upper = strtoupper($room->type ?? '');

        if (str_contains($upper, 'VIP') || str_contains($upper, 'SUITE')) {
            return 'vip';
        }

        if (str_contains($upper, 'PREMIUM')) {
            return 'premium';
        }

        return 'classic';
    }

    /**
     * Dedicated Lobby Customer Display Kiosk (Read-only, sanitized, 32-room 8x4 matrix).
     */
    public function kioskView(): View
    {
        $rooms = Room::with(['activeFolio' => fn ($query) => $query->select('folios.id', 'folios.room_id', 'folios.checked_in_at', 'folios.expected_checkout_at', 'folios.stay_mode')])->orderByNumber()->get();
        $totalRooms = $rooms->count();
        $availableRooms = $rooms->where('status', 'available')->count();
        $occupiedRooms = $rooms->where('status', 'occupied')->count();
        $maintenanceRooms = $rooms->where('status', 'maintenance')->count();

        return view('kiosk.index', compact(
            'rooms',
            'totalRooms',
            'availableRooms',
            'occupiedRooms',
            'maintenanceRooms'
        ));
    }
}
