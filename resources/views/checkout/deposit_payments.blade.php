@foreach($depositPayments as $payment)
    <div class="row">
        <span>Deposit received ({{ $payment['method'] === 'split' ? 'CASH + GCASH' : strtoupper($payment['method']) }}):</span>
        <span>₱{{ number_format($payment['amount'], 2) }}</span>
    </div>
    @if($payment['reference'] !== null && $payment['reference'] !== '')
        <div class="row">
            <span>GCash deposit ref #:</span>
            <span style="min-width: 0; overflow-wrap: anywhere; text-align: right;">{{ $payment['reference'] }}</span>
        </div>
    @endif
@endforeach
