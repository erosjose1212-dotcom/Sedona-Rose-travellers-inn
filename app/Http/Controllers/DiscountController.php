<?php

namespace App\Http\Controllers;

use App\Models\DiscountRate;
use App\Models\Folio;
use App\Services\AuditService;
use App\Services\DiscountService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class DiscountController extends Controller
{
    public function index(): View
    {
        $rates = DiscountService::rates();
        $folios = Folio::with(['room', 'guest'])->where('status', 'active')->whereHas('room', fn (Builder $query): Builder => $query->where('is_staff_quarters', false))->get()
            ->sortBy(fn (Folio $folio): int => (int) $folio->room->number)->values();

        return view('admin.discounts', compact('rates', 'folios'));
    }

    public function update(Request $request, AuditService $audit, DiscountService $discounts): RedirectResponse
    {
        $data = $request->validate([
            'discount_type' => ['required', 'in:SENIOR,DC'], 'room_tier' => ['required', 'in:CLASSIC,PREMIUM,VIP'],
            'amounts' => ['required', 'array:3h,6h,12h,24h'],
            'amounts.3h' => ['required', 'numeric', 'min:0', 'max:500000', 'decimal:0,2'],
            'amounts.6h' => ['required', 'numeric', 'min:0', 'max:500000', 'decimal:0,2'],
            'amounts.12h' => ['required', 'numeric', 'min:0', 'max:500000', 'decimal:0,2'],
            'amounts.24h' => ['required', 'numeric', 'min:0', 'max:500000', 'decimal:0,2'],
            'reason' => ['required', 'string', 'max:1000'],
        ]);
        DB::transaction(function () use ($request, $data, $audit): void {
            $before = [];
            $after = [];
            foreach (['3h', '6h', '12h', '24h'] as $duration) {
                $amount = $data['amounts'][$duration];
                $rate = DiscountRate::firstOrCreate(['discount_type' => $data['discount_type'], 'room_tier' => $data['room_tier'], 'duration' => $duration],
                    ['amount' => DiscountService::RATES[$data['discount_type']][$data['room_tier']][$duration]]);
                $rate = DiscountRate::whereKey($rate->id)->lockForUpdate()->firstOrFail();
                $before[$duration] = $rate->amount;
                $rate->update(['amount' => $amount, 'updated_by' => $request->user()->id]);
                $after[$duration] = $rate->amount;
            }
            $audit->record($request, 'discount.schedule_updated', $rate, ['discount_type' => $data['discount_type'], 'room_tier' => $data['room_tier'],
                'before' => $before, 'after' => $after, 'reason' => $data['reason']]);
        }, 3);
        $discounts->forgetRates();

        return back()->with('success', 'Discount defaults saved for future check-ins. Existing guest bills keep their recorded schedule.');
    }
}
