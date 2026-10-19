<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Deposit Refund Slip - {{ $folio->transaction_id }}</title>
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
        .row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
        }
        .banner {
            border: 1px solid #000;
            padding: 4px;
            text-align: center;
            font-weight: bold;
            font-size: 12px;
            margin: 6px 0;
            text-transform: uppercase;
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
        <form method="POST" action="{{ route('folios.deposit_refund.print', $folio) }}" style="display: inline;">
            @csrf
            <input type="hidden" name="operation_key" value="{{ \Illuminate\Support\Str::uuid() }}">
            <button class="btn-print">🖨️ Request refund summary print / copy</button>
        </form>
        <button class="btn-print" style="background:#4b5563; margin-left:6px;" onclick="window.close()">Close Window</button>
    </div>

    <div class="slip-container" id="printable-deposit-refund">
        <div class="text-center" style="font-size: 10px; font-weight: bold;">{{ isset($receiptCopy) ? ($receiptCopy === 1 ? 'FIRST RECORDED PRINT REQUEST' : 'COPY — PRINT REQUEST #'.$receiptCopy) : 'PREVIEW — NO PRINT REQUEST RECORDED' }}</div>
        <!-- Header -->
        <div class="text-center">
            <div class="font-bold" style="font-size: 14px; letter-spacing: 1px;">{{ config('sedona.hotel_name') }}</div>
            <div style="font-size: 9px;">{{ config('sedona.hotel_address') }}</div>
            <div style="font-size: 9px;">TEL: +63 (0939) 905-2816</div>
        </div>

        <div class="double-divider"></div>

        <div class="banner">SECURITY DEPOSIT REFUND / RESOLUTION</div>

        <!-- Info -->
        <div style="margin-top: 6px;">
            <div class="row">
                <span>Slip Ref:</span>
                <span class="font-bold">DEP-REF-{{ $folio->transaction_id }}</span>
            </div>
            <div class="row">
                <span>Room Number:</span>
                <span class="font-bold">ROOM {{ $folio->room->number ?? 'N/A' }}</span>
            </div>
            <div class="row">
                <span>Guest Name:</span>
                <span>{{ $folio->guest->name ?? 'Walk-In Guest' }}</span>
            </div>
            <div class="row">
                <span>Date & Time:</span>
                <span>{{ $folio->deposit_refunded_at?->format('m/d/Y h:i A') ?? 'Refund pending' }}</span>
            </div>
            <div class="row">
                <span>Attending Cashier:</span>
                <span>{{ $refundOperator?->name ?? 'Refund not recorded' }}</span>
            </div>
        </div>

        <div class="divider"></div>

        <!-- Financial Resolution -->
        <div>
            <div class="row">
                <span>Original Deposit Held:</span>
                <span>₱{{ number_format($depositAmount, 2) }}</span>
            </div>
            <div class="row">
                <span>Deductions / Damages / POS:</span>
                <span>₱{{ number_format($deductions, 2) }}</span>
            </div>
            <div class="double-divider"></div>
            <div class="row font-bold" style="font-size: 13px;">
                <span>TOTAL DEPOSIT REFUNDED:</span>
                <span>₱{{ number_format($refundAmount, 2) }}</span>
            </div>
            <div class="divider"></div>
            <div class="row">
                <span>Resolution Status:</span>
                <span class="font-bold uppercase">{{ $remainingDeposit <= 0 ? ($refundAmount > 0 ? 'REFUNDED & CLEARED' : 'NO REFUND DUE') : ($refundAmount > 0 ? 'PARTIALLY REFUNDED' : 'REFUND PENDING') }}</span>
            </div>
        </div>

        <div class="divider"></div>

        <div style="font-size: 9px; color: #333; line-height: 1.3;">
            @if($refundAmount > 0)
            I acknowledge receipt of the actual deposit refund recorded above.
            @elseif($remainingDeposit > 0)
            No deposit refund has been recorded. The remaining deposit requires an actual refund transaction.
            @else
            No deposit refund is due. The deposit has no remaining balance.
            @endif
        </div>

        <!-- Counter Signatures -->
        <div class="sig-block">
            <div class="sig-line">
                Guest Signature: {{ $folio->guest->name ?? 'Valued Guest' }}
            </div>
            <div class="sig-line" style="margin-top: 18px;">
                Last Refund Cashier: {{ $refundOperator?->name ?? 'Refund not recorded' }}
            </div>
        </div>

        <div class="divider" style="margin-top: 14px;"></div>

        <div class="row">
            <span>Deposit remaining to refund:</span>
            <span>₱{{ number_format($remainingDeposit, 2) }}</span>
        </div>
        <!-- Scannable Code 128 Barcode -->
        <div style="text-align: center; margin-top: 6px;">
            <div style="height: 34px; width: 85%; margin: 0 auto;">
                {!! \App\Services\BarcodeService::renderSvg('REF-' . $folio->transaction_id, 34) !!}
            </div>
            <div style="font-size: 9px; letter-spacing: 2px; font-weight: bold; margin-top: 3px;">
                *DEP-REF-{{ $folio->transaction_id }}*
            </div>
        </div>

        <div style="margin-top: 10px; text-align: center; font-size: 8px; color: #555;">
            {{ $refundAmount > 0 ? 'OFFICIAL DEPOSIT REFUND RECORD' : 'DEPOSIT REFUND SUMMARY' }} • {{ config('sedona.hotel_name') }}
        </div>
    </div>

    @if(!$folio->isActive() && $remainingDeposit > 0)
    <form method="POST" action="{{ route('folios.deposit_refund.store', $folio) }}" class="no-print" style="margin: 20px; text-align: center;" onsubmit="return confirm('Confirm the actual deposit refund to this guest?')">
        @csrf
        <input type="hidden" name="operation_key" value="{{ \Illuminate\Support\Str::uuid() }}">
        @include('shifts.override-reason')
        <p>Deposit remaining: {{ number_format($remainingDeposit, 2) }}</p>
        <label>Actual refund amount <input name="amount" type="number" min="0.01" max="{{ $remainingDeposit }}" step="0.01" value="{{ $remainingDeposit }}" required></label>
        <label>Refund method <select name="payment_method"><option value="cash">Cash</option><option value="gcash">GCash</option></select></label>
        <label>GCash refund reference <input name="gcash_reference" maxlength="50"></label>
        <button type="submit">Record actual refund</button>
    </form>
    @endif
</body>
@if($printRequested ?? false)
<script>window.addEventListener('load', () => window.print());</script>
@endif
</html>
