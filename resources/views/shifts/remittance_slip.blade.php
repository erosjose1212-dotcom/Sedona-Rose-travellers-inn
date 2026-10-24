<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Shift Remittance Slip - #{{ $shift->id }}</title>
    <style>
        @page {
            size: 80mm auto;
            margin: 0;
        }
        body {
            font-family: 'Courier New', Courier, monospace;
            font-size: 11px;
            line-height: 1.35;
            color: #000;
            background-color: #f5f5f0;
            margin: 0;
            padding: 16px 8px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .slip-container {
            width: 76mm;
            max-width: 100%;
            background-color: #fff;
            border: 1px solid #ddd;
            box-shadow: 0 4px 10px rgba(0,0,0,0.08);
            padding: 14px 12px;
            box-sizing: border-box;
        }
        .text-center { text-align: center; }
        .text-right { text-align: right; }
        .text-left { text-align: left; }
        .font-bold { font-weight: bold; }
        .divider {
            border-top: 1px dashed #000;
            margin: 8px 0;
        }
        .double-divider {
            border-top: 2px solid #000;
            margin: 8px 0;
        }
        .dotted-divider {
            border-top: 1px dotted #000;
            margin: 5px 0;
        }
        .row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
        }
        .section-header {
            font-weight: bold;
            text-transform: uppercase;
            font-size: 11px;
            padding-bottom: 2px;
            margin-top: 4px;
        }
        .sig-block {
            margin-top: 24px;
            text-align: center;
        }
        .sig-line {
            border-top: 1px solid #000;
            margin-top: 28px;
            padding-top: 4px;
            font-size: 10px;
            font-weight: bold;
        }
        .no-print {
            margin-bottom: 16px;
            text-align: center;
        }
        .btn-print {
            background-color: #421A2B;
            color: #fff;
            padding: 8px 18px;
            font-size: 12px;
            font-weight: bold;
            border: none;
            cursor: pointer;
            border-radius: 4px;
        }
        @media print {
            body { background-color: #fff; padding: 0; }
            .slip-container { border: none; box-shadow: none; padding: 4px; width: 100%; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>

    <div class="no-print">
        <button class="btn-print" onclick="window.print()">🖨️ Print 80mm Shift Remittance</button>
        <button class="btn-print" style="background:#4b5563; margin-left:6px;" onclick="window.close()">Close Window</button>
        <form method="GET" style="margin-top:10px;">
            <label>Shift date <input type="date" name="date" required value="{{ $reportPeriod['date'] }}"></label>
            <label>Shift <select name="shift"><option value="day" @selected($reportPeriod['shift'] === 'day')>Day · 6:30 AM–6:30 PM</option><option value="night" @selected($reportPeriod['shift'] === 'night')>Night · 6:30 PM–next 6:30 AM</option></select></label>
            <button type="submit" class="btn-print">Show shift</button>
        </form>
    </div>

    <div class="slip-container" id="printable-shift-remittance">
        <!-- Header -->
        <div class="text-center">
            <div class="font-bold" style="font-size: 14px; letter-spacing: 1px;">{{ config('sedona.hotel_name') }}</div>
            <div style="font-size: 9px; margin-top: 2px;">STATION FD-01</div>
            <div style="font-size: 8px; color: #555;">{{ config('sedona.shift_handover_enabled') ? 'SHIFT TERMINAL HANDOVER REPORT' : 'SHIFT DRAWER RECONCILIATION REPORT' }}</div>
        </div>

        <div class="double-divider"></div>

        <!-- Shift Meta -->
        <div>
            <div class="row">
                <span>PRINTED ON:</span>
                <span>{{ now()->format('m/d/Y h:i A') }}</span>
            </div>
            <div class="row">
                <span>OUTGOING OP:</span>
                <span class="font-bold">{{ $propertyAccess ? ($shift->openedBy->name ?? 'Frontdesk Cashier') : auth()->user()->name }}</span>
            </div>
            @if(config('sedona.shift_handover_enabled') || $shift->accepted_by)
                <div class="row">
                    <span>INCOMING OP:</span>
                    <span class="font-bold">{{ $incomingCashier ?? 'Next Shift Operator' }}</span>
                </div>
            @endif
            <div class="row">
                <span>SHIFT BASIS:</span>
                <span class="font-bold">12-HOUR ROTATIONAL</span>
            </div>
            <div class="row">
                <span>ACTIVE SHIFT:</span>
                <span class="font-bold">{{ strtoupper($reportPeriod['label']) }}</span>
            </div>
            <div class="row">
                <span>OPENED AT:</span>
                <span>{{ $shift->opened_at ? $shift->opened_at->format('m/d/Y h:i A') : 'N/A' }}</span>
            </div>
            <div class="row">
                <span>CLOSED AT:</span>
                <span>{{ $shift->closed_at ? $shift->closed_at->format('m/d/Y h:i A') : 'DRAWER STILL OPEN' }}</span>
            </div>
            <div style="margin-top:5px;">REPORT: {{ $reportPeriod['start']->format('m/d h:i A') }}–{{ $reportPeriod['end']->format('m/d h:i A') }}</div>
            <div style="font-size:9px;margin-top:3px;">{{ $propertyAccess ? 'Admin/owner financial totals cover the full drawer record.' : 'Money: your transactions in this shift only. Guest activity covers all rooms.' }}</div>
        </div>

        <div class="divider"></div>

        <!-- Section I: Financial Reconciliation -->
        <div>
            <div class="section-header">I. RECONCILIATION SUMMARY</div>
            @if($propertyAccess)
            <div class="row">
                <span>STARTING CASH FLOAT:</span>
                <span>₱{{ number_format($openingFloat, 2) }}</span>
            </div>
            <div class="row">
                <span>CASH COLLECTIONS (ROOMS/POS):</span>
                <span>₱{{ number_format($cashSales, 2) }}</span>
            </div>
            <div class="row">
                <span>GCASH / DIGITAL COLLECTIONS:</span>
                <span>₱{{ number_format($gcashSales, 2) }}</span>
            </div>
            <div class="dotted-divider"></div>
            <div class="row font-bold">
                <span>TOTAL NET COLLECTIONS:</span>
                <span>₱{{ number_format($totalRevenue, 2) }}</span>
            </div>
            <div class="row">
                <span>TOTAL CASH INFLOW (FLOAT + CASH):</span>
                <span>₱{{ number_format($openingFloat + $cashSales, 2) }}</span>
            </div>
            <div class="row" style="color: #421A2B;">
                <span>LESS: CASH EXPENSES PAID OUT:</span>
                <span>-₱{{ number_format($totalExpenses, 2) }}</span>
            </div>
            <div class="row">
                <span>NET TRANSFERS / REPLENISHMENT:</span>
                <span>{{ $cashMovementTotal > 0 ? '+' : '' }}₱{{ number_format($cashMovementTotal, 2) }}</span>
            </div>
            <div class="dotted-divider"></div>
            <div class="row font-bold" style="font-size: 11px;">
                <span>EXPECTED DRAWER CASH:</span>
                <span>₱{{ number_format($expectedCash, 2) }}</span>
            </div>
            <div class="row font-bold" style="font-size: 12px;">
                <span>ACTUAL PHYSICAL DRAWER COUNT:</span>
                <span>{{ $actualCash === null ? 'Not counted yet' : '₱'.number_format($actualCash, 2) }}</span>
            </div>
            <div class="row font-bold" style="margin-top: 3px; font-size: 11px;">
                <span>DRAWER VARIANCE:</span>
                <span style="{{ $variance < 0 ? 'color: #c00;' : ($variance > 0 ? 'color: #007;' : 'color: #080;') }}">
                    @if($variance === null)Not counted yet @else
                        {{ $variance >= 0 ? '+' : '' }}₱{{ number_format($variance, 2) }}
                        ({{ $variance == 0 ? 'BALANCED' : ($variance > 0 ? 'OVERAGE' : 'SHORTAGE') }})
                    @endif
                </span>
            </div>
            @else
                <div class="row"><span>MY NET CASH COLLECTIONS:</span><strong>₱{{ number_format($cashSales, 2) }}</strong></div>
                <div class="row"><span>MY NET GCASH COLLECTIONS:</span><strong>₱{{ number_format($gcashSales, 2) }}</strong></div>
                <div class="row font-bold"><span>MY TOTAL NET COLLECTIONS:</span><span>₱{{ number_format($totalRevenue, 2) }}</span></div>
                <div class="row"><span>MY CASH EXPENSES:</span><span>₱{{ number_format($totalExpenses, 2) }}</span></div>
                <div class="row"><span>MY NET CASH TRANSFERS:</span><span>₱{{ number_format($cashMovementTotal, 2) }}</span></div>
                <div class="row font-bold"><span>MY NET CASH AFTER CASH OUTS:</span><span>₱{{ number_format($expectedCash, 2) }}</span></div>
                <div style="font-size:9px;margin-top:4px;">Includes deposits and refunds. This is not the physical shared drawer balance.</div>
            @endif
        </div>

        <div class="divider"></div>

        <!-- Section II: Shift Operational Expenses (Paid Out) -->
        <div>
            <div class="section-header">II. OPERATIONAL EXPENSES (PAID OUT)</div>
            @if($shiftExpenses->isEmpty())
                <div style="font-style: italic; color: #555; padding: 4px 0;">No cash expenses disbursed during this shift.</div>
            @else
                <table style="width: 100%; border-collapse: collapse; margin-top: 4px; font-size: 10px;">
                    <thead>
                        <tr style="border-bottom: 1px dotted #000; text-align: left;">
                            <th style="padding: 2px 0;">VOUCHER / ITEM</th>
                            <th style="padding: 2px 0; text-align: right;">AMOUNT</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach($shiftExpenses as $exp)
                            <tr>
                                <td style="padding: 2px 0;">
                                    <div class="font-bold">{{ $exp->voucher_number }}</div>
                                    <div style="font-size: 9px; color: #444;">{{ Str::limit($exp->description, 26) }} [{{ strtoupper($exp->category) }}]</div>
                                </td>
                                <td style="padding: 2px 0; text-align: right; vertical-align: top; font-weight: bold;">
                                    ₱{{ number_format($exp->amount, 2) }}
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
                <div class="dotted-divider"></div>
                <div class="row font-bold">
                    <span>TOTAL SHIFT EXPENSES:</span>
                    <span style="color: #421A2B;">₱{{ number_format($totalExpenses, 2) }}</span>
                </div>
            @endif
        </div>

        <div class="divider"></div>

        <div>
            <div class="section-header">III. DRAWER CUSTODY MOVEMENTS</div>
            @forelse($cashMovements as $movement)
                <div class="row">
                    <span>{{ strtoupper(str_replace('_', ' ', $movement->kind)) }} / {{ strtoupper(str_replace('_', ' ', $movement->destination)) }}</span>
                    <span>{{ (float) $movement->amount > 0 ? '+' : '' }}₱{{ number_format($movement->amount, 2) }}</span>
                </div>
                <div style="font-size: 9px; margin-bottom: 4px; overflow-wrap: anywhere;">REF: {{ $movement->reference }}</div>
            @empty
                <div style="font-style: italic; color: #555; padding: 4px 0;">No cash transfers or replenishment during this shift.</div>
            @endforelse
        </div>

        <div class="divider"></div>

        <!-- Section IV: Room Occupancy Status -->
        <div>
            <div class="section-header">IV. ROOM OCCUPANCY REPORT</div>
            <div style="font-size:9px;margin-bottom:5px;">GUEST ACTIVITY · {{ $reportPeriod['date'] }} · all rooms</div>
            <table style="width:100%;border-collapse:collapse;font-size:10px;">
                <thead><tr style="text-align:left;border-bottom:1px dotted #000;"><th>SHIFT</th><th>CHECK-INS</th><th>CHECKOUTS</th></tr></thead>
                <tbody>
                    @foreach($handover['activity']['shifts'] as $activity)
                        <tr><td style="padding:4px 0;">{{ $activity['label'] }}<br><small>{{ $activity['start']->format('g:i A') }}–{{ $activity['end']->format('g:i A') }}</small></td><td>{{ $activity['check_ins']['stays'] }} stays<br>{{ $activity['check_ins']['guests'] }} guests</td><td>{{ $activity['check_outs']['stays'] }} stays<br>{{ $activity['check_outs']['guests'] }} guests</td></tr>
                    @endforeach
                </tbody>
            </table>
            <div class="dotted-divider"></div>
            <div style="font-size:9px;margin-bottom:5px;">CURRENT OCCUPANCY · {{ $handover['occupancyAt']->format('m/d h:i A') }}<br>Live at printing; not a historical room snapshot.</div>
            <div class="row">
                <span>OCCUPIED ROOMS:</span>
                <span class="font-bold">{{ $occupiedCount }} Rooms</span>
            </div>
            <div class="row">
                <span>VACANT AVAILABLE:</span>
                <span class="font-bold">{{ $availableCount }} Rooms</span>
            </div>
            <div class="row">
                <span>MAINTENANCE / BLOCKED:</span>
                <span>{{ $maintenanceCount }} Rooms</span>
            </div>
            <table style="width:100%;font-size:9px;border-collapse:collapse;">
                <thead><tr style="border-bottom:1px dotted #000;text-align:left;"><th>ROOM / GUESTS</th><th>CHECK-IN</th><th>EXPECTED OUT</th></tr></thead>
                <tbody>@forelse($handover['rooms']->where('status', 'occupied') as $room)
                    <tr><td style="padding:4px 0;">{{ $room->number }} · {{ $room->activeFolio?->guest?->headcount ?? '—' }} guests</td><td>{{ $room->activeFolio?->checked_in_at?->copy()->setTimezone('Asia/Manila')->format('m/d g:i A') ?? 'Not recorded' }}</td><td>{{ $room->activeFolio?->isOpenTime() ? 'Open Time' : ($room->activeFolio?->expected_checkout_at?->copy()->setTimezone('Asia/Manila')->format('m/d g:i A') ?? 'Not recorded') }}</td></tr>
                @empty<tr><td colspan="3" style="padding:4px 0;">No occupied guest rooms.</td></tr>@endforelse</tbody>
            </table>
            <div class="dotted-divider"></div>
            <div class="section-header">ROOM TRANSFERS · {{ strtoupper($reportPeriod['label']) }}</div>
            @forelse($handover['transfers'] as $transfer)
                <div style="margin:4px 0;">Room {{ $transfer['from'] }} → Room {{ $transfer['to'] }} · {{ $transfer['at']->format('g:i A') }}<br><small>{{ $transfer['reference'] }}</small></div>
            @empty<div style="font-size:9px;">No room transfers recorded in this shift.</div>@endforelse
        </div>

        <div class="divider"></div>

        <!-- Section V: Counter Signatures -->
        <div class="sig-block">
            <div class="sig-line">
                Outgoing Cashier: {{ $propertyAccess ? ($shift->openedBy->name ?? 'Turnover Operator') : auth()->user()->name }}
            </div>
            @if(config('sedona.shift_handover_enabled') || $shift->accepted_by)
                <div class="sig-line" style="margin-top: 20px;">
                    Incoming Cashier: {{ $incomingCashier ?? 'Receiving Operator' }}
                </div>
            @endif
            <div class="sig-line" style="margin-top: 20px;">
                Audited &amp; Verified by Duty Manager
            </div>
        </div>

        <div style="margin-top: 14px; text-align: center; font-size: 8px; color: #555;">
            *** OFFICIAL SHIFT TURNOVER SLIP • {{ config('sedona.hotel_name') }} ***
        </div>
    </div>

</body>
</html>
