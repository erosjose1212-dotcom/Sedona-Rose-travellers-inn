@extends('layouts.app')

@section('title', 'Check-in Time Corrections')
@section('subtitle', 'RA declaration time · Admin / Owner')

@section('top_action')
    <a href="{{ route('admin.check-in-times.index') }}" class="inline-flex items-center justify-center rounded-lg bg-brand px-4 py-2 font-semibold text-white hover:bg-brand-hover">All active stays</a>
@endsection

@section('content')
<div class="space-y-5">
    <div class="hms-card">
        <h2 class="hms-card-header">Correct a guest's declaration time</h2>
        <p class="text-sm text-slate-600">Enter when RA declared the guest. Billing, checkout time, alarms and stay reports will use this time. The maximum checkout grace period remains 15 minutes.</p>
        <p class="text-sm text-slate-600 mt-2">Every correction keeps the previous time, corrected time, reason and admin in the audit history.</p>
        <p class="text-sm text-slate-600 mt-2">After saving, refresh any open Front Desk or checkout screen.</p>
    </div>

    @if($errors->any())
        <div role="alert" class="hms-card border border-rose-300 bg-rose-50">
            <p class="font-bold">The correction could not be saved.</p>
            <ul class="list-disc pl-5">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </div>
    @endif

    @forelse($folios as $folio)
        <section class="hms-card" id="time-correction-{{ $folio->id }}">
            <div class="flex flex-wrap justify-between gap-3 mb-4">
                <div>
                    <h2 class="font-bold">Room {{ $folio->room->number }} — {{ $folio->guest->name }}</h2>
                    <p class="text-xs text-slate-600">{{ $folio->transaction_id }} · {{ strtoupper($folio->rate_tier) }} package · Recorded by {{ $folio->cashier?->name ?? 'Staff' }}</p>
                </div>
                <a href="{{ route('checkout.process', $folio) }}" class="text-sm text-brand underline">View guest bill</a>
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4 text-sm">
                <p><span class="text-slate-500">Current check-in:</span> <strong>{{ $folio->checked_in_at->format('m/d/Y h:i:s A') }}</strong></p>
                <p><span class="text-slate-500">{{ $folio->isOpenTime() ? 'Open Time base ends:' : 'Current checkout:' }}</span> <strong>{{ $folio->expected_checkout_at->format('m/d/Y h:i A') }}</strong></p>
            </div>
            <form method="POST" action="{{ route('admin.check-in-times.update', $folio) }}" class="grid grid-cols-1 sm:grid-cols-2 gap-4" data-time-correction-form>
                @csrf
                <input type="hidden" name="operation_key" value="{{ old('folio_id') == $folio->id ? old('operation_key', (string) \Illuminate\Support\Str::uuid()) : (string) \Illuminate\Support\Str::uuid() }}">
                <input type="hidden" name="folio_id" value="{{ $folio->id }}">
                <input type="hidden" name="expected_checked_in_at" value="{{ $folio->checked_in_at->toIso8601String() }}">
                <label class="block text-sm font-bold">RA declaration time (hotel time)
                    <input type="datetime-local" name="checked_in_at" required max="{{ now()->format('Y-m-d\TH:i') }}" value="{{ old('folio_id') == $folio->id ? old('checked_in_at', $folio->checked_in_at->format('Y-m-d\TH:i')) : $folio->checked_in_at->format('Y-m-d\TH:i') }}" class="form-control-hms block mt-1" data-corrected-time>
                </label>
                <label class="block text-sm font-bold">Reason for correction (required)
                    <input type="text" name="reason" required maxlength="500" placeholder="Type the reason for changing the check-in time" value="{{ old('folio_id') == $folio->id ? old('reason') : '' }}" class="form-control-hms block mt-1">
                </label>
                <p role="alert" data-correction-feedback hidden class="text-sm font-bold text-rose-700 sm:col-span-2"></p>
                <p class="text-sm text-slate-600 self-center">New checkout: <strong data-corrected-checkout data-package-hours="{{ $folio->room->getTierHours($folio->rate_tier) + (int) $folio->extra_hours }}" data-midnight-promo="{{ $folio->rate_tier === 'promo' ? 'true' : 'false' }}" data-extra-hours="{{ (int) $folio->extra_hours }}"></strong></p>
                <button type="submit" class="inline-flex items-center justify-center rounded-lg bg-brand px-4 py-2 font-semibold text-white hover:bg-brand-hover justify-self-start sm:justify-self-end">Save corrected time</button>
            </form>
        </section>
    @empty
        <div class="hms-card text-slate-600">No active guest stays to correct.</div>
    @endforelse

    <section class="hms-card">
        <h2 class="hms-card-header">Recent time corrections</h2>
        <div class="overflow-x-auto">
            <table class="hms-table">
                <thead><tr><th>STAY</th><th>PREVIOUS TIME</th><th>RA DECLARATION TIME</th><th>REASON / ADMIN</th></tr></thead>
                <tbody>
                    @forelse($history as $entry)
                        <tr>
                            <td>{{ $entry['reference'] }}<br><span class="text-xs text-slate-500">Folio #{{ $entry['folio_id'] }}</span></td>
                            <td>{{ $entry['before'] }}</td><td>{{ $entry['after'] }}</td>
                            <td class="text-left">{{ $entry['reason'] }}<br><span class="text-xs text-slate-500">{{ $entry['operator'] }} · {{ $entry['recorded_at'] }}</span></td>
                        </tr>
                    @empty
                        <tr><td colspan="4" class="text-slate-500">No time corrections recorded.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
@endsection

@push('scripts')
<script>
    document.querySelectorAll('[data-time-correction-form]').forEach(form => {
        const input = form.querySelector('[data-corrected-time]');
        const preview = form.querySelector('[data-corrected-checkout]');
        const feedback = form.querySelector('[data-correction-feedback]');
        form.addEventListener('invalid', event => {
            feedback.textContent = event.target.name === 'reason'
                ? 'The correction was not sent. Enter a reason for changing the check-in time, then save again.'
                : `The correction was not sent. Check the RA declaration time: ${event.target.validationMessage}`;
            feedback.hidden = false;
        }, true);
        form.addEventListener('input', () => {
            feedback.hidden = true;
        });
        const updatePreview = () => {
            if (!input.value || !input.checkValidity()) {
                preview.textContent = 'Choose a valid declaration time';
                return;
            }
            const next = new Date(input.value + ':00Z');
            if (preview.dataset.midnightPromo === 'true') {
                const hour = next.getUTCHours();
                if (hour >= 6 && hour < 20) {
                    preview.textContent = 'Midnight promo check-in must be between 8 PM and 6 AM';
                    return;
                }
                if (hour >= 20) next.setUTCDate(next.getUTCDate() + 1);
                next.setUTCHours(6 + Number(preview.dataset.extraHours), 0, 0, 0);
            } else {
                next.setUTCHours(next.getUTCHours() + Number(preview.dataset.packageHours));
            }
            preview.textContent = new Intl.DateTimeFormat('en-US', { timeZone: 'UTC', month: '2-digit', day: '2-digit', year: 'numeric', hour: '2-digit', minute: '2-digit', hour12: true }).format(next);
        };
        input.addEventListener('input', updatePreview);
        updatePreview();
    });
</script>
@endpush
