@extends('layouts.app')

@section('title', 'Operating Expenses & Petty Cash Ledger')
@section('subtitle', 'Accounting & Financial Hub | Operating Expense Records & Petty Cash Vouchers')

@section('top_action')
    <button onclick="openExpenseModal()" class="bg-[#421A2B] hover:bg-[#341421] text-white font-bold text-[11px] px-3 py-1 rounded shadow-sm">
        + Log New Expense Voucher
    </button>
@endsection

@section('content')
<div class="px-3 pt-2 space-y-3 max-w-7xl mx-auto">

    <!-- KPI Summary -->
    <div class="grid grid-cols-1 sm:grid-cols-3 gap-2 text-xs">
        <div class="bg-white p-3 border-l-4 border-rose-600 shadow-sm">
            <span class="font-bold text-slate-500 uppercase text-[10px] block">This Month's Operating Expenses</span>
            <div class="text-xl font-mono font-black text-rose-700 mt-0.5">₱{{ number_format($thisMonthTotal, 2) }}</div>
            <div class="text-[10px] text-slate-400 mt-0.5">Utilities, laundry, maintenance & staff meals</div>
        </div>

        <div class="bg-white p-3 border-l-4 border-amber-600 shadow-sm">
            <span class="font-bold text-slate-500 uppercase text-[10px] block">Today's Disbursements</span>
            <div class="text-xl font-mono font-black text-amber-700 mt-0.5">₱{{ number_format($todayTotal, 2) }}</div>
            <div class="text-[10px] text-slate-400 mt-0.5">Cash drawer & petty cash settlements</div>
        </div>

        <div class="bg-white p-3 border-l-4 border-slate-700 shadow-sm">
            <span class="font-bold text-slate-500 uppercase text-[10px] block">Total Vouchers Logged</span>
            <div class="text-xl font-mono font-bold text-slate-900 mt-0.5">{{ $expenses->total() }} Records</div>
            <div class="text-[10px] text-slate-400 mt-0.5">Complete audited expense history</div>
        </div>
    </div>

    <!-- Category Filter Bar -->
    <div class="bg-white p-2.5 border-l-4 border-[#421A2B] shadow-sm flex flex-wrap items-center justify-between gap-2 text-xs">
        <div class="font-bold text-slate-800 uppercase tracking-wide">
            Filter by Expense Category:
        </div>
        <div class="flex items-center flex-wrap gap-1">
            <a href="{{ route('admin.accounting.expenses', ['category' => 'all']) }}" class="px-2.5 py-1 rounded text-xs font-bold {{ $selectedCategory === 'all' ? 'bg-[#421A2B] text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200 border border-slate-300' }}">
                All Categories
            </a>
            @foreach($categories as $key => $label)
                <a href="{{ route('admin.accounting.expenses', ['category' => $key]) }}" class="px-2.5 py-1 rounded text-xs font-bold {{ $selectedCategory === $key ? 'bg-[#421A2B] text-white' : 'bg-slate-100 text-slate-700 hover:bg-slate-200 border border-slate-300' }}">
                    {{ $label }}
                </a>
            @endforeach
        </div>
    </div>

    <!-- Expense Table -->
    <div class="hms-card">
        <div class="hms-card-header border-b pb-2 mb-2 flex items-center justify-between">
            <span>OPERATING EXPENSE VOUCHER JOURNAL</span>
        </div>

        <div class="overflow-x-auto">
            <table class="hms-table">
                <thead>
                    <tr>
                        <th>VOUCHER #</th>
                        <th>DATE</th>
                        <th>CATEGORY</th>
                        <th>DESCRIPTION / PARTICULARS</th>
                        <th>SOURCE</th>
                        <th>OR / REF #</th>
                        <th>RECORDED BY</th>
                        <th>AMOUNT (₱)</th>
                        <th>ACTIONS</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse($expenses as $exp)
                        <tr>
                            <td class="font-mono font-bold text-[#421A2B]">{{ $exp->voucher_number }}</td>
                            <td class="font-mono text-[11px]">{{ $exp->expense_date->format('m/d/Y') }}</td>
                            <td class="font-bold uppercase text-[10px]">{{ ucwords(str_replace('_', ' ', $exp->category)) }}</td>
                            <td class="text-left pl-3 font-medium">{{ $exp->description }}</td>
                            <td>
                                <span class="px-2 py-0.5 rounded text-[10px] font-bold uppercase {{ $exp->payment_source === 'cash_drawer' ? 'bg-amber-100 text-amber-800' : 'bg-blue-100 text-blue-800' }}">
                                    {{ str_replace('_', ' ', $exp->payment_source) }}
                                </span>
                            </td>
                            <td class="font-mono text-slate-600 text-[11px]">{{ $exp->receipt_reference ?? '—' }}</td>
                            <td>{{ $exp->user->name ?? 'Admin' }}</td>
                            <td class="font-mono font-bold text-rose-700 text-right pr-3">₱{{ number_format($exp->amount, 2) }}<div class="text-xs font-normal">{{ str_replace('_', ' ', $exp->status) }}@if($exp->reversal_of_id) · reverses #{{ $exp->reversal_of_id }}@endif</div></td>
                            <td>
                                <form method="POST" action="{{ route('admin.accounting.expenses.delete', $exp->id) }}" >
                                    @csrf
            <input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                                    @method('DELETE')
                                    <input name="reason" required maxlength="1000" placeholder="Reason for cash return" class="form-control-hms">
                                    <input name="evidence_reference" required maxlength="255" placeholder="Return evidence reference" class="form-control-hms">
                                    <button type="submit" class="px-2 py-0.5 bg-rose-100 hover:bg-rose-200 text-rose-800 font-bold text-[10px] rounded border border-rose-300">
                                        Request cash return
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr>
                            <td colspan="9" class="py-8 text-center text-slate-400">No expense records found.</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($expenses->hasPages())
            <div class="p-2 border-t mt-2">
                {{ $expenses->links() }}
            </div>
        @endif
    </div>

</div>

<!-- MODAL: Log New Expense -->
<div id="expenseModal" class="fixed inset-0 z-50 flex items-center justify-center p-3 bg-black/60 hidden">
    <div class="bg-white w-full max-w-md border-t-4 border-[#421A2B] p-5 shadow-2xl">
        <div class="flex items-center justify-between border-b pb-2 mb-3">
            <h3 class="font-bold text-[#421A2B] text-sm">Log New Operating Expense Voucher</h3>
            <button onclick="closeExpenseModal()" class="text-slate-500 hover:text-black font-bold text-lg">&times;</button>
        </div>

        <form method="POST" action="{{ route('admin.accounting.expenses.store') }}" class="space-y-3 text-xs">
            @csrf
            <input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            @include('shifts.override-reason')

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Expense Date:</label>
                    <input type="date" name="expense_date" required value="{{ app(\App\Services\BusinessDayService::class)->date() }}" class="form-control-hms font-mono">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Payment Source:</label>
                    <select name="payment_source" required class="form-control-hms font-bold">
                        <option value="cash_drawer">Cash Drawer Register</option>
                        <option value="petty_cash">Executive Petty Cash Fund</option>
                        <option value="bank_transfer">Bank / Online Wire</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Expense Category:</label>
                <select name="category" required class="form-control-hms font-bold">
                    @foreach($categories as $key => $label)
                        <option value="{{ $key }}">{{ $label }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Particulars / Description:</label>
                <input type="text" name="description" required placeholder="e.g. Meralco Electric Bill, Laundry Detergent, Meat Restock" class="form-control-hms">
            </div>

            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-slate-700 mb-1">Disbursed Amount (₱):</label>
                    <input type="number" step="0.01" min="1" name="amount" required placeholder="0.00" class="form-control-hms font-mono font-bold">
                </div>
                <div>
                    <label class="block font-bold text-slate-700 mb-1">OR / Invoice # (Optional):</label>
                    <input type="text" name="receipt_reference" placeholder="e.g. OR-88129" class="form-control-hms font-mono">
                </div>
            </div>

            <div>
                <label class="block font-bold text-slate-700 mb-1">Internal Notes:</label>
                <textarea name="notes" rows="2" placeholder="Auditor notes, supplier name, etc." class="form-control-hms"></textarea>
            </div>

            <div class="pt-3 border-t flex justify-end space-x-2">
                <button type="button" onclick="closeExpenseModal()" class="px-3 py-1.5 bg-slate-200 text-slate-700 font-bold rounded">Cancel</button>
                <button type="submit" class="px-4 py-1.5 bg-[#421A2B] hover:bg-[#341421] text-white font-bold rounded shadow">Save Expense Voucher</button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
    function openExpenseModal() {
        document.getElementById('expenseModal').classList.remove('hidden');
    }
    function closeExpenseModal() {
        document.getElementById('expenseModal').classList.add('hidden');
    }
</script>
@endpush
@endsection
