@extends('layouts.app')
@section('title', 'Accounting Setup')
@section('subtitle', 'Owner configuration · accountant-approved policy and mappings')
@section('content')
<div class="hms-card max-w-5xl">
    <p class="text-sm text-slate-600 mb-5">Business-day cutoff: 6:30 AM, Asia/Manila. Invoice and CSV export activation require approved configuration. Tax extraction does not add to guest prices. External API synchronization is not connected.</p>
    <form method="POST" action="{{ route('admin.finance.setup.update') }}" class="grid gap-5">@csrf
        <fieldset @disabled(!auth()->user()->can('manage-accounting-setup')) class="grid gap-4 sm:grid-cols-2">
        @foreach(['legal_name' => 'Registered legal name', 'address' => 'Registered address', 'tin' => 'Tax identification number', 'registration' => 'Registration details', 'approval_reference' => 'Accountant approval reference', 'invoice_series' => 'Approved invoice series', 'opening_balance_reference' => 'Opening balance reconciliation reference', 'destination' => 'Accounting CSV destination'] as $key => $label)<label>{{ $label }}<input name="{{ $key }}" value="{{ old($key, $settings[$key] ?? '') }}" maxlength="{{ $key === 'address' ? 500 : ($key === 'invoice_series' ? 50 : ($key === 'destination' ? 100 : 255)) }}" class="form-control-hms"></label>@endforeach
            <label>Tax treatment<select name="tax_mode" class="form-control-hms">@foreach(['unconfigured' => 'Awaiting approval', 'vat_inclusive' => 'Approved VAT inclusive treatment', 'non_vat' => 'Non-VAT', 'exempt' => 'Tax exempt'] as $value => $label)<option value="{{ $value }}" @selected(($settings['tax_mode'] ?? '') === $value)>{{ $label }}</option>@endforeach</select></label>
            <label>Approved tax rate in basis points<input name="tax_rate_bps" type="number" min="0" max="10000" value="{{ $settings['tax_rate_bps'] ?? '' }}" class="form-control-hms"><span class="text-xs text-slate-500">100 basis points = 1%. Enter the actual approved rate.</span></label>
            <label>Next invoice sequence<input name="next_sequence" type="number" min="1" max="99999999" value="{{ $settings['next_sequence'] ?? 1 }}" class="form-control-hms"></label>
            @foreach(['invoice_enabled' => 'Enable configured invoices', 'export_enabled' => 'Enable reviewed accounting CSV exports'] as $key => $label)<label class="flex gap-2 items-center"><input name="{{ $key }}" type="hidden" value="0"><input name="{{ $key }}" type="checkbox" value="1" @checked($settings[$key] ?? false)>{{ $label }}</label>@endforeach
            <p class="sm:col-span-2 text-sm text-slate-600">Recognition policy: settled guest folios and completed walk-in sales. Deposits remain liabilities until applied or refunded. Review this policy and all mappings with your accountant before activation.</p>
            @foreach($accounts as $account)<label>{{ ucwords(str_replace('_', ' ', $account)) }} account<input name="account_mappings[{{ $account }}]" value="{{ $settings['account_mappings'][$account] ?? '' }}" maxlength="100" class="form-control-hms"></label>@endforeach
            <button class="btn-hms-primary sm:col-span-2">Save approved configuration</button>
        </fieldset>
    </form>
</div>
@endsection
