@extends('layouts.app')
@section('title', 'Invoice '.$invoice->number)
@section('subtitle', 'Issued configuration and immutable sale snapshot')
@section('content')
<div class="hms-card max-w-3xl mx-auto">
    <h1 class="text-2xl font-bold">{{ $invoice->document['issuer']['legal_name'] }}</h1><p>{{ $invoice->document['issuer']['address'] }}</p><p>TIN {{ $invoice->document['issuer']['tin'] }} · {{ $invoice->document['issuer']['registration'] }}</p>
    <h2 class="text-xl font-semibold mt-5">Invoice {{ $invoice->number }}</h2><p>{{ $invoice->document['issued_at'] }} · {{ $invoice->document['customer'] }}</p><p>Transaction {{ $invoice->document['transaction_id'] }} · {{ class_basename($invoice->document['source_type']) }} #{{ $invoice->document['source_id'] }}</p>
    <table class="w-full text-sm mt-5"><tbody>@foreach(['gross_cents' => 'Gross sale', 'discount_cents' => 'Discount', 'net_cents' => 'Invoice total', 'tax_base_cents' => 'Amount excluding recorded tax', 'taxable_cents' => 'Taxable portion, inclusive of tax', 'exempt_cents' => 'Exempt portion', 'tax_cents' => 'Included tax', 'deposit_applied_cents' => 'Deposit applied as payment'] as $key => $label)<tr class="border-b"><td class="py-3">{{ $label }}</td><td class="text-right">₱{{ number_format($invoice->document[$key] / 100, 2) }}</td></tr>@endforeach</tbody></table>
    <p class="text-xs text-slate-500 mt-4">Tax treatment: {{ $invoice->document['issuer']['tax_mode'] }}. Approval reference: {{ $invoice->document['issuer']['approval_reference'] }}.</p>
    <p class="mt-4">{{ isset($copy) ? 'Recorded print request #'.$copy : 'Preview' }}. Print requests do not confirm paper printing.</p>
    <form method="POST" action="{{ route('invoices.print', $invoice) }}" class="mt-4 print:hidden">@csrf<input name="operation_key" type="hidden" value="{{ (string) \Illuminate\Support\Str::uuid() }}"><button class="btn-hms-primary">Record print request</button></form>
</div>
@if($printRequested ?? false)<script>window.addEventListener('load', () => window.print());</script>@endif
@endsection
