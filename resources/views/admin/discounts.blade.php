@extends('layouts.app')
@section('title', 'Discounts')
@section('subtitle', 'Guest discount corrections and defaults for future check-ins')
@section('content')
<div class="space-y-5 max-w-7xl mx-auto">
    @if(session('success'))
        <div role="status" class="rounded-xl border border-emerald-200 bg-emerald-50 p-4 text-sm text-emerald-800">{{ session('success') }}</div>
    @endif
    @if($errors->any())
        <div role="alert" class="rounded-xl border border-rose-200 bg-rose-50 p-4 text-sm text-rose-800">
            <p class="font-semibold">Please correct the following:</p>
            <ul class="list-disc pl-5 mt-2">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif
    <section class="hms-card space-y-4">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div><h2 class="font-semibold text-slate-900">Change a current guest's discount</h2><p class="mt-1 text-sm text-slate-600">Choose the guest below, enter the correction and submit a request.</p></div>
            <a href="{{ route('approvals.index') }}" class="btn-occupied !px-4 !py-2 !text-sm !whitespace-normal">Open Approvals &amp; Tasks</a>
        </div>
        <p class="text-sm text-slate-600">{{ auth()->user()->hasRole('owner') ? 'Your room-rate and custom-discount requests are automatically approved.' : 'A different admin or owner must approve the request.' }} Then click <strong>Apply approved action</strong> in Approvals &amp; Tasks and reload the guest's checkout page. The bill changes when the approved action is applied.</p>
        <div class="divide-y divide-slate-200">
            @forelse($folios as $folio)
                @php($discount = app(\App\Services\BillingService::class)->calculate($folio, null, false)['totalDiscount'])
                <details class="py-4">
                    <summary class="cursor-pointer flex flex-wrap items-center gap-x-4 gap-y-1 text-sm">
                        <span class="font-semibold text-slate-900">Room {{ $folio->room->number }} · {{ $folio->guest?->name ?? 'Guest' }}</span>
                        <span class="text-slate-500">Folio #{{ $folio->id }} · {{ $folio->rate_tier }}</span>
                        <span class="font-medium">{{ $folio->discount_type ?? 'NONE' }} · ₱{{ number_format($discount, 2) }} discount</span>
                        <span class="text-[#6B2848] font-semibold">Edit discount</span>
                    </summary>
                    <form method="POST" action="{{ route('approvals.store') }}" class="mt-4 grid gap-4 sm:grid-cols-2">
                        @csrf
                        <input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                        <input type="hidden" name="kind" value="folio_override">
                        <input type="hidden" name="source_id" value="{{ $folio->id }}">
                        <label class="text-sm font-medium">Discount type
                            <select name="payload[discount_type]" required class="form-control-hms mt-1">
                                @foreach(['NONE' => 'No discount', 'DC' => 'Sedona Card / DC', 'SENIOR' => 'Senior Citizen', 'PWD' => 'PWD'] as $type => $label)
                                    <option value="{{ $type }}" @selected(strtoupper($folio->discount_type ?? 'NONE') === $type)>{{ $label }}</option>
                                @endforeach
                            </select>
                        </label>
                        <label class="text-sm font-medium">Discount ID reference
                            <input name="payload[discount_id_ref]" value="{{ $folio->discount_id_ref }}" maxlength="100" class="form-control-hms mt-1">
                            <span class="block mt-1 text-xs text-slate-500">Required for Senior Citizen or PWD. Use the guest's valid ID reference.</span>
                        </label>
                        <label class="text-sm font-medium">Discount amount (₱, optional)
                            <input name="payload[discount_override_amount]" type="number" step="0.01" min="0" max="{{ $folio->room_charge }}" value="{{ $folio->discount_override_amount }}" class="form-control-hms mt-1" placeholder="Use this guest's recorded default">
                            <span class="block mt-1 text-xs text-slate-500">Leave blank to use the default saved at check-in. A custom amount applies only to this guest and cannot exceed the room charge. Clear this amount when removing a discount.</span>
                        </label>
                        <label class="text-sm font-medium">Evidence reference (optional)
                            <input name="evidence_reference" maxlength="255" class="form-control-hms mt-1" placeholder="Supporting document or authorization">
                        </label>
                        <label class="text-sm font-medium sm:col-span-2">Reason for the correction
                            <textarea name="reason" required maxlength="1000" rows="2" class="form-control-hms mt-1" placeholder="Explain why this guest's discount needs to change"></textarea>
                        </label>
                        <div class="sm:col-span-2"><button class="btn-occupied !px-4 !py-2 !text-sm !whitespace-normal">Request discount change for Room {{ $folio->room->number }}</button></div>
                    </form>
                </details>
            @empty
                <p class="py-4 text-sm text-slate-500">No active guest bills to edit.</p>
            @endforelse
        </div>
        <p class="text-xs text-slate-500">Completed bills cannot be edited. Requests, reviews and execution are retained in the audit history.</p>
    </section>
    <section class="space-y-4">
        <div class="hms-card">
            <h2 class="font-semibold text-slate-900">Default discounts for future guests</h2>
            <p class="mt-2 text-sm text-slate-600">These are fixed peso amounts. Senior Citizen and PWD share one schedule. Saving here applies to check-ins made after the change; guests already checked in keep their saved schedule.</p>
            <p class="mt-2 text-sm text-slate-600">The existing promo rule uses the 3-hour discount. Discounts reduce the room charge only and are capped at that charge.</p>
        </div>
        <div class="grid gap-4 lg:grid-cols-2">
            @foreach(['DC' => 'Sedona Card / DC', 'SENIOR' => 'Senior Citizen / PWD'] as $category => $categoryLabel)
                @foreach(['CLASSIC' => 'Classic', 'PREMIUM' => 'Premium', 'VIP' => 'VIP'] as $tier => $tierLabel)
                    @php($isRetry = old('discount_type') === $category && old('room_tier') === $tier)
                    <form method="POST" action="{{ route('admin.discounts.update') }}" class="hms-card space-y-4">
                        @csrf
                        <input name="discount_type" type="hidden" value="{{ $category }}">
                        <input name="room_tier" type="hidden" value="{{ $tier }}">
                        <h3 class="font-semibold text-slate-900">{{ $categoryLabel }} · {{ $tierLabel }}</h3>
                        <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                            @foreach(['3h' => '3 hours', '6h' => '6 hours', '12h' => '12 hours', '24h' => '24 hours'] as $duration => $durationLabel)
                                <label class="min-w-0 text-xs font-semibold text-slate-600">{{ $durationLabel }} (₱)
                                    <input name="amounts[{{ $duration }}]" type="number" step="0.01" min="0" max="500000" required value="{{ $isRetry ? old('amounts.'.$duration, $rates[$category][$tier][$duration]) : number_format($rates[$category][$tier][$duration], 2, '.', '') }}" class="form-control-hms mt-1">
                                </label>
                            @endforeach
                        </div>
                        <label class="block text-sm font-medium">Reason for changing defaults
                            <input name="reason" required maxlength="1000" value="{{ $isRetry ? old('reason') : '' }}" class="form-control-hms mt-1" placeholder="e.g. Updated discount policy">
                        </label>
                        <button class="btn-occupied !px-4 !py-2 !text-sm !whitespace-normal">Save {{ $tierLabel }} defaults</button>
                    </form>
                @endforeach
            @endforeach
        </div>
    </section>
</div>
@endsection
