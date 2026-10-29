<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Deposit Slip - {{ $folio->transaction_id }}</title>
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
        .thermal-container {
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
            margin: 6px 0;
        }
        .double-divider {
            border-top: 2px solid #000;
            margin: 6px 0;
        }
        .row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2px;
        }
        .banner {
            border: 1px solid #000;
            padding: 4px;
            text-align: center;
            font-weight: bold;
            font-size: 11px;
            margin: 6px 0;
            text-transform: uppercase;
        }
        .sig-block {
            margin-top: 20px;
            text-align: center;
        }
        .sig-line {
            border-top: 1px solid #000;
            margin-top: 26px;
            padding-top: 4px;
            font-size: 10px;
            font-weight: bold;
        }
        .no-print {
            margin-bottom: 16px;
            text-align: center;
        }
        .btn-print {
            background-color: #0275d8;
            color: #fff;
            padding: 8px 16px;
            font-size: 12px;
            font-weight: bold;
            border: none;
            cursor: pointer;
            border-radius: 4px;
        }
        @media print {
            body { background-color: #fff; padding: 0; }
            .thermal-container { border: none; box-shadow: none; padding: 4px; width: 100%; }
            .no-print { display: none; }
        }
    </style>
</head>
<body>

    <div class="no-print">
        <form method="POST" action="{{ route('folios.deposit_slip.print', $folio) }}" style="display: inline;">
            @csrf
            <input type="hidden" name="operation_key" value="{{ \Illuminate\Support\Str::uuid() }}">
            <button class="btn-print">🖨️ Request deposit slip print / copy</button>
        </form>
        <button class="btn-print" style="background:#4b5563; margin-left:6px;" onclick="window.close()">Close</button>
    </div>

    <div class="thermal-container" id="printable-deposit-slip">
        <div class="text-center" style="font-size: 10px; font-weight: bold;">{{ isset($receiptCopy) ? ($receiptCopy === 1 ? 'FIRST RECORDED PRINT REQUEST' : 'COPY — PRINT REQUEST #'.$receiptCopy) : 'PREVIEW — NO PRINT REQUEST RECORDED' }}</div>
        <!-- Header -->
        <div class="text-center">
            <div class="font-bold" style="font-size: 14px; letter-spacing: 1px;">{{ config('sedona.hotel_name') }}</div>
            <div style="font-size: 9px;">{{ config('sedona.hotel_address') }}</div>
            <div style="font-size: 9px;">TEL: +63 (0939) 905-2816</div>
        </div>

        <div class="double-divider"></div>
        <div class="banner">SECURITY DEPOSIT LEDGER &amp; ACKNOWLEDGMENT</div>
        <div class="text-center" style="font-size: 8px; color: #444;">* OFFICIAL SECURITY DEPOSIT RECEIPT • TRUST ACCOUNT *</div>
        <div class="divider"></div>

        <!-- Meta -->
        <div class="row">
            <span>Deposit Slip #:</span>
            <span class="font-bold">DEP-{{ $folio->transaction_id }}</span>
        </div>
        <div class="row">
            <span>Folio Ref #:</span>
            <span class="font-bold">{{ $folio->transaction_id }}</span>
        </div>
        <div class="row">
            <span>Date &amp; Time:</span>
            <span>{{ ($folio->deposit_collected_at ?? $folio->checked_in_at)->format('m/d/Y h:i A') }}</span>
        </div>
        <div class="row">
            <span>Room Number:</span>
            <span class="font-bold">ROOM {{ $folio->room->number ?? 'N/A' }} ({{ $folio->room->type ?? '' }})</span>
        </div>
        <div class="row">
            <span>Guest Name:</span>
            <span>{{ $folio->guest->name ?? 'Walk-In Guest' }}</span>
        </div>
        <div class="row">
            <span>Issuing Cashier:</span>
            <span>{{ $folio->cashier->name ?? 'Front Desk Staff' }} // FD-01</span>
        </div>
        <div class="row">
            <span>Payment Method:</span>
            <span class="font-bold">{{ ($depositPaymentMethod ?? '') === 'split' ? 'CASH + GCASH' : strtoupper($depositPaymentMethod ?? $folio->deposit_payment_method ?? 'cash') }}</span>
        </div>
        <div class="row">
            <span>Trust Status:</span>
            <span class="font-bold">{{ $depositAmount <= 0 ? 'NONE' : strtoupper(str_replace('_', ' ', $folio->deposit_status ?: 'HELD IN TRUST')) }}</span>
        </div>

        <div class="double-divider"></div>

        @include('checkout.deposit_payments', ['depositPayments' => $depositPayments ?? []])

        <!-- Amount Box -->
        <div style="text-align: center; padding: 8px 0;">
            <div style="font-size: 10px; font-weight: bold; text-transform: uppercase;">SECURITY DEPOSIT AMOUNT HELD IN TRUST:</div>
            <div class="font-bold" style="font-size: 20px; margin-top: 4px;">
                ₱{{ number_format($depositAmount, 2) }}
            </div>
        </div>

        <div class="double-divider"></div>

        <!-- Signatures -->
        <div class="sig-block">
            <div class="sig-line">
                Guest Signature: {{ $folio->guest->name ?? 'Valued Guest' }}
            </div>
            <div class="sig-line" style="margin-top: 18px;">
                Authorized Cashier: {{ $folio->cashier->name ?? 'Front Desk Staff' }}
            </div>
        </div>

        <div class="divider" style="margin-top: 14px;"></div>

        <div class="text-center" style="font-size: 8px; margin-top: 8px; color: #555;">
            *** OFFICIAL SECURITY DEPOSIT LEDGER SLIP • {{ config('sedona.hotel_name') }} ***
        </div>
    </div>

</body>
@if($printRequested ?? false)
<script>window.addEventListener('load', () => window.print());</script>
@endif
</html>
