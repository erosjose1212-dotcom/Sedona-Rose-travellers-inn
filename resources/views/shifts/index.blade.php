@extends('layouts.app')

@section('title', 'Cashier Shifts & Cash Drawer Management')
@section('subtitle', config('sedona.continuous_shift_enabled') ? 'One shared cash drawer for all cashier accounts' : 'Start and close cashier shifts with cash drawer reconciliation')

@section('top_action')
    <a href="{{ route('dashboard') }}" class="bg-[#333333] hover:bg-[#222222] text-white text-[11px] font-bold px-3 py-1 rounded shadow-sm border border-[#666666]">
        &larr; Return to Dashboard
    </a>
@endsection

@section('content')
<div class="px-3 pt-2 space-y-4 max-w-6xl mx-auto">

    <!-- Header -->
    <div class="workspace-toolbar flex flex-wrap items-center justify-between gap-3 text-xs">
        <div>
            <h1 class="font-bold text-slate-900 text-sm uppercase tracking-wide">Cashier Shift Management & Cash Drawer Reconciliation</h1>
            @if(config('sedona.continuous_shift_enabled'))
                <p class="text-slate-500">One continuous shift is shared by all cashiers. Sign out when you finish; the next cashier signs in and uses the same drawer. Each payment records the cashier who handled it.</p>
                @unless($activeShift)<p class="text-slate-500">Start the drawer once with the actual opening cash. It stays open after that.</p>@endunless
            @else
                <p class="text-slate-500">Start your shift before taking payments. Keep it open while working; count and close the drawer when your shift ends.</p>
            @endif
            @if(!config('sedona.shift_handover_enabled') && !config('sedona.continuous_shift_enabled'))
                <p class="text-slate-500">The next cashier starts a new shift with the actual opening cash. Handover acceptance is temporarily disabled.</p>
            @endif
        </div>

        @if(!$activeShift && $drawerStatus['state'] === 'no_shift')
            <form method="POST" action="{{ route('shifts.open') }}" class="flex flex-wrap items-end gap-3">
                @csrf
            <input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                <div class="flex items-center space-x-1">
                    <label for="shiftOpeningCash" class="text-[11px] font-bold text-slate-600">Starting cash in drawer (₱):</label>
                    <input id="shiftOpeningCash" type="number" name="opening_float" required value="{{ old('opening_float') }}" placeholder="0.00" step="0.01" min="0" max="100000" class="form-control-hms w-28 text-right font-mono font-bold text-xs">
                </div>
                <button type="submit" class="px-4 py-1.5 bg-[#0284c7] hover:bg-[#0369a1] text-white font-bold text-xs rounded shadow">
                    Start cashier shift
                </button>
                <p class="basis-full text-xs text-slate-600">Enter the actual cash now in the drawer. Use 0 if it is empty.</p>
            </form>
        @elseif($canOperateActiveShift)
            <div class="flex items-center space-x-2">
                <button type="button" onclick="openExpenseModal()" @disabled($activeShift->is_frozen) class="px-3 py-1.5 bg-amber-600 hover:bg-amber-700 text-white font-bold text-xs rounded shadow flex items-center space-x-1">
                    <span>💸</span>
                    <span>Log Shift Expense (Cash Out)</span>
                </button>
                <a href="{{ route('shifts.remittance_slip', $activeShift->id) }}" target="_blank" class="px-3 py-1.5 bg-[#333333] hover:bg-[#222222] text-white font-bold text-xs rounded shadow flex items-center space-x-1">
                    <span>🖨️</span>
                    <span>80mm Turnover Slip Preview</span>
                </a>
            </div>
        @endif
    </div>

    @if(!$activeShift && $drawerStatus['state'] === 'handover')
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-5 mx-2 my-2" role="status">
            <h2 class="font-semibold">Waiting for drawer handover</h2>
            <p class="mt-1 text-sm">{{ $drawerStatus['message'] }}</p>
            <p class="mt-2 text-sm">Accepting the handover starts the incoming cashier's shift automatically. There is no need to start a second shift.</p>
        </div>
    @endif

    @foreach($pendingHandovers as $handover)
        <div class="hms-card"><h2 class="font-semibold">Drawer handover #{{ $handover->id }} · {{ $handover->handover_status }}</h2><p class="text-sm">Outgoing count ₱{{ $handover->cash_total }}. A different incoming operator must count and accept custody. Any disagreement requires independent review.</p>
            @if($handover->opened_by === auth()->id())
                <p class="mt-2 text-sm font-semibold text-amber-800">Another cashier must accept your closed drawer. Ask the incoming cashier to sign in, open Shifts &amp; Drawer, and count and accept this handover.</p>
            @elseif($handover->incoming_user_id && $handover->incoming_user_id !== auth()->id())
                <p class="mt-2 text-sm text-slate-600">This handover is assigned to a different incoming cashier.</p>
            @elseif($handover->handover_status === 'disputed')
                <p class="mt-2 text-sm text-amber-800">An admin or owner must review the count disagreement in Approvals &amp; Tasks before this handover can be accepted.</p>
            @endif
            @if(in_array($handover->handover_status, ['pending', 'variance_approved']) && $handover->opened_by !== auth()->id() && (!$handover->incoming_user_id || $handover->incoming_user_id === auth()->id()))
            <form method="POST" action="{{ route('shifts.accept', $handover) }}" class="flex flex-wrap gap-3 mt-4">@csrf<input name="operation_key" type="hidden" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><label>Incoming count<input name="accepted_cash" required type="number" step="0.01" min="0" class="form-control-hms"></label><label>Handover notes<input name="notes" required maxlength="1000" class="form-control-hms"></label><button class="btn-hms-primary">Count and accept handover</button></form>
            @endif
        </div>
    @endforeach
    <div class="text-sm"><a href="{{ route('approvals.index') }}" class="underline">Request cash drops, replenishment or bank deposits; review pending tasks</a>. {{ config('sedona.continuous_shift_enabled') ? 'Sign out when you finish. The shared drawer stays open.' : 'Count and close your drawer before signing out.' }}</div>

    <!-- Active Shift Box (if open) -->
    @if($activeShift && !$canOperateActiveShift)
        <div class="hms-card" role="status">
            <strong>Drawer in use by {{ $activeShift->openedBy->name ?? 'another cashier' }}.</strong>
            <p>Ask the assigned cashier or an administrator to complete closeout before opening your shift. Guest check-in and room charges remain available.</p>
        </div>
    @endif
    @if($canOperateActiveShift)
        <div class="hms-card">
            <div class="flex items-center justify-between border-b pb-2 mb-3">
                <div class="flex items-center space-x-2">
                    <span class="font-bold text-[#421A2B] text-sm uppercase tracking-wide">
                        ACTIVE {{ strtoupper($activeShift->shift_type) }} SHIFT IN PROGRESS
                    </span>
                    <span class="px-2 py-0.5 bg-emerald-100 text-emerald-800 font-bold text-[10px] rounded uppercase">
                        {{ $activeShift->is_frozen ? 'Frozen — posting blocked' : 'Drawer open' }}
                    </span>
                </div>
                <div class="text-xs text-slate-600 font-mono">
                    Opened by: <strong>{{ $activeShift->openedBy->name ?? 'Cashier' }}</strong> on {{ $activeShift->opened_at->format('m/d/Y h:i A') }}
                </div>
            </div>

            <!-- Reconciliation Financial Formula Grid -->
            <div class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-4 text-xs">
                <div class="p-2.5 bg-slate-50 border border-slate-200">
                    <span class="font-bold text-slate-500 uppercase text-[10px] block">1. Starting Float</span>
                    <div class="font-mono font-bold text-base text-slate-800 mt-0.5">₱{{ number_format($openingFloat, 2) }}</div>
                </div>

                <div class="p-2.5 bg-slate-50 border border-slate-200">
                    <span class="font-bold text-slate-500 uppercase text-[10px] block">2. Net Cash Collections</span>
                    <div class="font-mono font-bold text-base text-emerald-700 mt-0.5">₱{{ number_format($cashRevenue, 2) }}</div>
                </div>

                <div class="p-2.5 bg-rose-50 border border-rose-200">
                    <span class="font-bold text-rose-700 uppercase text-[10px] block">3. Cash Expenses (Paid Out)</span>
                    <div class="font-mono font-bold text-base text-rose-700 mt-0.5">-₱{{ number_format($totalExpenses, 2) }}</div>
                </div>

                <div class="p-2.5 bg-sky-50 border border-sky-200">
                    <span class="font-bold text-sky-800 uppercase text-[10px] block">4. Expected Drawer Cash</span>
                    <div class="font-mono font-extrabold text-base text-sky-900 mt-0.5">₱{{ number_format($expectedCash, 2) }}</div>
                    <span class="text-[9px] text-slate-500 font-semibold block mt-0.5">[Float + Cash Inflow - Cash Expenses]</span>
                </div>

                <div class="p-2.5 bg-slate-50 border border-slate-200">
                    <span class="font-bold text-slate-500 uppercase text-[10px] block">Net GCash Collections</span>
                    <div class="font-mono font-bold text-base text-blue-700 mt-0.5">₱{{ number_format($gcashCollections, 2) }}</div>
                </div>
            </div>

            <p class="text-xs text-slate-500 mb-3">Collections include deposit receipts and refunds. Earned revenue is reported separately.</p>

            <!-- Shift Operational Disbursements Journal -->
            <div class="border border-slate-200 rounded p-3 bg-slate-50/50 mb-4">
                <div class="flex items-center justify-between mb-2">
                    <div class="font-bold text-xs text-slate-800 uppercase tracking-wide flex items-center space-x-1.5">
                        <span>💸</span>
                        <span>Shift Operational Disbursements (Cash Outlay From Drawer)</span>
                    </div>
                    <span class="text-xs font-mono font-bold text-rose-700">
                        Total Disbursements: ₱{{ number_format($totalExpenses, 2) }}
                    </span>
                </div>

                @if($shiftExpenses->isEmpty())
                    <div class="py-2 text-center text-slate-400 text-xs italic bg-white border border-dashed rounded">
                        No cash disbursements recorded for this shift yet. Use "Log Shift Expense" above to record out-of-pocket operational costs.
                    </div>
                @else
                    <div class="overflow-x-auto bg-white border rounded">
                        <table class="w-full text-xs text-left">
                            <thead class="bg-slate-100 text-[10px] uppercase text-slate-600 font-bold border-b">
                                <tr>
                                    <th class="p-2">Voucher #</th>
                                    <th class="p-2">Category</th>
                                    <th class="p-2">Description</th>
                                    <th class="p-2">Receipt Ref</th>
                                    <th class="p-2">Time</th>
                                    <th class="p-2 text-right">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-slate-100">
                                @foreach($shiftExpenses as $exp)
                                    <tr>
                                        <td class="p-2 font-mono font-bold text-slate-800">{{ $exp->voucher_number }}</td>
                                        <td class="p-2"><span class="px-1.5 py-0.5 bg-slate-100 text-slate-700 rounded text-[10px] uppercase font-bold">{{ $exp->category }}</span></td>
                                        <td class="p-2 text-slate-800">{{ $exp->description }}</td>
                                        <td class="p-2 font-mono text-slate-500">{{ $exp->receipt_reference ?? '—' }}</td>
                                        <td class="p-2 font-mono text-slate-500">{{ $exp->created_at->format('h:i A') }}</td>
                                        <td class="p-2 font-mono font-bold text-rose-700 text-right">₱{{ number_format($exp->amount, 2) }}</td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                @endif
            </div>

            <!-- Counted Shift Closeout Form -->
            @unless(config('sedona.continuous_shift_enabled'))
            <form method="POST" action="{{ route('shifts.close', $activeShift->id) }}" class="space-y-3 pt-3 border-t border-slate-200">
                @csrf
            <input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">

                <div class="section-bar-grey">PHYSICAL CASH DRAWER COUNT (COUNTED CLOSEOUT)</div>

                <div class="grid grid-cols-2 sm:grid-cols-4 md:grid-cols-7 gap-2 text-xs">
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">₱1,000 Bills</label>
                        <input type="number" name="denominations[1000]" id="d1000" value="{{ $activeShift->denomination_count['1000'] ?? 0 }}" min="0" oninput="calculateDrawerTotal()" class="form-control-hms font-mono text-center font-bold">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">₱500 Bills</label>
                        <input type="number" name="denominations[500]" id="d500" value="{{ $activeShift->denomination_count['500'] ?? 0 }}" min="0" oninput="calculateDrawerTotal()" class="form-control-hms font-mono text-center font-bold">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">₱200 Bills</label>
                        <input type="number" name="denominations[200]" id="d200" value="{{ $activeShift->denomination_count['200'] ?? 0 }}" min="0" oninput="calculateDrawerTotal()" class="form-control-hms font-mono text-center font-bold">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">₱100 Bills</label>
                        <input type="number" name="denominations[100]" id="d100" value="{{ $activeShift->denomination_count['100'] ?? 0 }}" min="0" oninput="calculateDrawerTotal()" class="form-control-hms font-mono text-center font-bold">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">₱50 Bills</label>
                        <input type="number" name="denominations[50]" id="d50" value="{{ $activeShift->denomination_count['50'] ?? 0 }}" min="0" oninput="calculateDrawerTotal()" class="form-control-hms font-mono text-center font-bold">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">₱20 Bills</label>
                        <input type="number" name="denominations[20]" id="d20" value="{{ $activeShift->denomination_count['20'] ?? 0 }}" min="0" oninput="calculateDrawerTotal()" class="form-control-hms font-mono text-center font-bold">
                    </div>
                    <div>
                        <label class="block text-[11px] font-bold text-slate-700 mb-1">Coins Total (₱)</label>
                        <input type="number" step="0.01" name="denominations[coins]" id="dCoins" value="{{ $activeShift->denomination_count['coins'] ?? 0 }}" min="0" oninput="calculateDrawerTotal()" class="form-control-hms font-mono text-center font-bold">
                    </div>
                </div>

                <!-- Real-time Count vs Expected Cash Reconciliation Box -->
                <div class="p-3 bg-slate-100 border border-slate-300 rounded grid grid-cols-1 sm:grid-cols-3 gap-3 items-center text-xs">
                    <div>
                        <span class="font-bold text-slate-800 uppercase text-[11px]">Counted Drawer Cash:</span>
                        <div class="font-mono font-black text-emerald-800 text-lg" id="countedDrawerTotal">
                            ₱0.00
                        </div>
                    </div>
                    <div>
                        <span class="font-bold text-slate-600 uppercase text-[11px]">Expected Cash Balance:</span>
                        <div class="font-mono font-bold text-slate-800 text-base">
                            ₱{{ number_format($expectedCash, 2) }}
                        </div>
                    </div>
                    <div id="varianceContainer">
                        <span class="font-bold text-slate-600 uppercase text-[11px]">Audit Discrepancy / Variance:</span>
                        <div class="font-mono font-black text-base" id="varianceValue">
                            ₱0.00 (Balanced)
                        </div>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold text-slate-700 mb-1">Shift Closing Notes / Special Incidents:</label>
                    <textarea name="handoff_notes" rows="2" placeholder="e.g. Room 13 currently extended by 3 hours. Key issued for Room 7. Cash float balanced." class="form-control-hms">{{ $activeShift->handoff_notes }}</textarea>
                </div>

                @include('shifts.override-reason')
                <div class="flex items-center justify-end space-x-2 pt-2">
                    <button type="submit" @disabled($activeShift->is_frozen) onclick="return confirm('Close shift and record the final drawer count?')" class="px-5 py-2 bg-[#421A2B] hover:bg-[#341421] text-white font-bold text-xs rounded shadow">
                        Close Shift & Record Count
                    </button>
                </div>
            </form>
            @endunless
        </div>
    @endif

    <!-- Past Closed Shifts Ledger -->
    <div class="hms-card">
        <div class="hms-card-header border-b pb-2 mb-2 flex items-center justify-between">
            <span>PAST SHIFT TURNOVER LOG</span>
            <span class="text-[11px] font-normal text-slate-500">Shifts you opened, closed, or participated in; administrators can review all shifts</span>
        </div>
        <div class="overflow-x-auto">
            <table class="hms-table">
                <thead>
                    <tr>
                        <th>SHIFT DATE</th>
                        <th>TYPE</th>
                        <th>OPENED BY</th>
                        <th>CLOSED BY</th>
                        <th>FLOAT</th>
                        <th>EXPENSES</th>
                        <th>COUNTED CASH</th>
                        <th>EXPECTED CASH</th>
                        <th>VARIANCE</th>
                        <th>HANDOFF NOTES</th>
                        <th>ACTION</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($pastShifts as $sh)
                        <tr>
                            <td class="font-mono">{{ $sh->shift_date->format('m/d/Y') }}</td>
                            <td class="font-bold uppercase text-[10px]">{{ $sh->shift_type }}</td>
                            <td>{{ $sh->openedBy->name ?? 'Staff' }}</td>
                            <td>{{ $sh->closedBy->name ?? 'Staff' }}</td>
                            <td class="font-mono">₱{{ number_format($sh->opening_float ?? 5000, 2) }}</td>
                            <td class="font-mono text-rose-700">₱{{ number_format($sh->total_expenses ?? 0, 2) }}</td>
                            <td class="font-mono font-bold text-emerald-800">₱{{ number_format($sh->cash_total, 2) }}</td>
                            <td class="font-mono text-slate-700">₱{{ number_format($sh->expected_cash ?? 0, 2) }}</td>
                            <td class="font-mono font-bold {{ ($sh->cash_variance ?? 0) < 0 ? 'text-rose-700' : (($sh->cash_variance ?? 0) > 0 ? 'text-blue-700' : 'text-emerald-700') }}">
                                {{ ($sh->cash_variance ?? 0) >= 0 ? '+' : '' }}₱{{ number_format($sh->cash_variance ?? 0, 2) }}
                            </td>
                            <td class="text-left pl-2 text-[11px] text-slate-600 max-w-xs truncate">{{ $sh->handoff_notes ?? '—' }}</td>
                            <td>
                                <a href="{{ route('shifts.remittance_slip', $sh->id) }}" target="_blank" class="px-2 py-0.5 bg-slate-800 hover:bg-black text-white text-[10px] font-bold rounded">
                                    🖨️ Slip
                                </a>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="11" class="py-4 text-center text-slate-400">No past closed shifts recorded.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

</div>

<!-- Modal: Log Shift Operational Expense -->
<div id="expenseModal" class="fixed inset-0 bg-black/50 z-50 hidden flex items-center justify-center p-3">
    <div class="bg-white rounded-lg shadow-xl max-w-md w-full border border-slate-300 overflow-hidden">
        <div class="bg-[#421A2B] text-white px-4 py-2.5 flex items-center justify-between font-bold text-xs uppercase tracking-wider">
            <span>💸 Record Shift Petty Cash Expense (Paid Out)</span>
            <button type="button" onclick="closeExpenseModal()" class="text-white hover:text-slate-200 text-base leading-none">&times;</button>
        </div>

        <form method="POST" action="{{ route('shifts.expense.store') }}" class="p-4 space-y-3 text-xs">
            @csrf
            <input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            @include('shifts.override-reason')

            <div class="p-2 bg-amber-50 border border-amber-200 text-amber-900 text-[11px] rounded">
                This amount will be disbursed in cash immediately from the drawer and subtracted from the expected shift turnover total.
            </div>

            <div>
                <label class="block text-[11px] font-bold text-slate-700 mb-1">Expense Category: <span class="text-rose-600">*</span></label>
                <select name="category" required class="form-control-hms">
                    <option value="supplies">Cleaning & Guest Supplies</option>
                    <option value="maintenance">Room Repairs & Maintenance</option>
                    <option value="food_beverage">Kitchen & Bar Provisions</option>
                    <option value="utilities">Emergency Fuel / Utilities</option>
                    <option value="transport">Errands & Transport</option>
                    <option value="other">Other Operational Expense</option>
                </select>
            </div>

            <div>
                <label class="block text-[11px] font-bold text-slate-700 mb-1">Disbursement Description: <span class="text-rose-600">*</span></label>
                <input type="text" name="description" required placeholder="e.g. 5x Emergency toilet flush valve washers" class="form-control-hms">
            </div>

            <div class="grid grid-cols-2 gap-2">
                <div>
                    <label class="block text-[11px] font-bold text-slate-700 mb-1">Amount (₱): <span class="text-rose-600">*</span></label>
                    <input type="number" step="0.01" name="amount" min="1" max="100000" required placeholder="0.00" class="form-control-hms font-mono font-bold text-right text-sm">
                </div>
                <div>
                    <label class="block text-[11px] font-bold text-slate-700 mb-1">Receipt / Invoice Ref:</label>
                    <input type="text" name="receipt_reference" placeholder="e.g. OR-88192" class="form-control-hms font-mono">
                </div>
            </div>

            <div>
                <label class="block text-[11px] font-bold text-slate-700 mb-1">Operational Notes (Optional):</label>
                <textarea name="notes" rows="2" placeholder="Details about who requested or authorized the payout" class="form-control-hms"></textarea>
            </div>

            <div class="flex items-center justify-end space-x-2 pt-2 border-t">
                <button type="button" onclick="closeExpenseModal()" class="px-3 py-1.5 bg-slate-200 hover:bg-slate-300 text-slate-700 font-bold rounded text-xs">
                    Cancel
                </button>
                <button type="submit" class="px-4 py-1.5 bg-amber-600 hover:bg-amber-700 text-white font-bold rounded text-xs shadow">
                    Confirm Cash Payout
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
    const expectedCashValue = {{ $expectedCash ?? 5000.00 }};

    function calculateDrawerTotal() {
        const d1000 = (parseInt(document.getElementById('d1000')?.value) || 0) * 1000;
        const d500  = (parseInt(document.getElementById('d500')?.value) || 0) * 500;
        const d200  = (parseInt(document.getElementById('d200')?.value) || 0) * 200;
        const d100  = (parseInt(document.getElementById('d100')?.value) || 0) * 100;
        const d50   = (parseInt(document.getElementById('d50')?.value) || 0) * 50;
        const d20   = (parseInt(document.getElementById('d20')?.value) || 0) * 20;
        const dCoins = parseFloat(document.getElementById('dCoins')?.value) || 0;

        const total = d1000 + d500 + d200 + d100 + d50 + d20 + dCoins;
        const totalEl = document.getElementById('countedDrawerTotal');
        if (totalEl) {
            totalEl.textContent = `₱${total.toLocaleString('en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 })}`;
        }

        const variance = total - expectedCashValue;
        const varianceEl = document.getElementById('varianceValue');
        if (varianceEl) {
            let label = "Exact";
            let color = "text-emerald-700";
            if (variance > 0) {
                label = `+₱${variance.toFixed(2)} (Overage)`;
                color = "text-blue-700";
            } else if (variance < 0) {
                label = `-₱${Math.abs(variance).toFixed(2)} (Shortage)`;
                color = "text-rose-700";
            } else {
                label = `₱0.00 (Balanced)`;
            }
            varianceEl.textContent = label;
            varianceEl.className = `font-mono font-black text-base ${color}`;
        }
    }

    function openExpenseModal() {
        document.getElementById('expenseModal')?.classList.remove('hidden');
    }

    function closeExpenseModal() {
        document.getElementById('expenseModal')?.classList.add('hidden');
    }

    calculateDrawerTotal();
</script>
@endpush
@endsection
