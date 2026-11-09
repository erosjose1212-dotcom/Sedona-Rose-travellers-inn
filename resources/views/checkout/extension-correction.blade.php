@extends('layouts.app')
@section('title', 'Correct saved extension')
@section('content')
<div class="hms-card space-y-4">
    <p><strong>Room {{ $folio->room->number }}</strong> · {{ $folio->transaction_id }}</p>
    <p>Current extension: {{ $folio->extra_hours }} hours. Enter the corrected total extension hours. The original package and add-ons stay on the bill.</p>
    @if($errors->any())
        <ul role="alert">@foreach($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
    @endif
    <form method="GET" action="{{ route('folios.extension.preview', $folio) }}">
        <label>Corrected total extension hours <input type="number" name="hours" min="0" max="8760" required value="{{ $hours }}" class="form-control-hms"></label>
        <button class="btn-occupied">Preview correction</button>
    </form>
    @if($hours !== (int) $folio->extra_hours)
        <p>Expected checkout: {{ $preview['checkout']->format('M j, Y g:i A') }}</p>
        <p>Bill after discount: ₱{{ number_format($preview['before']['amountAfterDiscount'], 2) }} → ₱{{ number_format($preview['after']['amountAfterDiscount'], 2) }}</p>
        <p>Current balance due: ₱{{ number_format($preview['before']['finalBalanceToPay'], 2) }} → ₱{{ number_format($preview['after']['finalBalanceToPay'], 2) }}</p>
        @if($folio->billing_printed_at)<p>A billing copy was previously printed. Replace it with the corrected bill after saving.</p>@endif
        <form method="POST" action="{{ route('folios.extension.correct', $folio) }}">
            @csrf
            <input type="hidden" name="hours" value="{{ $hours }}">
            <input type="hidden" name="preview_key" value="{{ $preview['key'] }}">
            <input type="hidden" name="operation_key" value="{{ \Illuminate\Support\Str::uuid() }}">
            <label>Reason for correction <textarea name="reason" required maxlength="500" class="form-control-hms">{{ old('reason') }}</textarea></label>
            <button class="btn-occupied">Save correction</button>
        </form>
    @endif
    <a class="underline" href="{{ route('checkout.process', $folio) }}">Return to checkout</a>
</div>
@endsection
