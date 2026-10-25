<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Billing Statement - Room {{ $folio->room->number ?? '' }}</title>
    <style>
        @page {
            size: 80mm auto;
            margin: 0;
        }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
            line-height: 1.25;
            color: #000;
            background-color: #f5f5f0;
            margin: 0;
            padding: 16px 8px;
            display: flex;
            flex-direction: column;
            align-items: center;
        }
        .thermal-container {
            width: 72mm;
            max-width: 100%;
            background-color: #fff;
            border: 1px solid #ddd;
            box-shadow: 0 4px 10px rgba(0,0,0,0.08);
            padding: 14px 12px;
            box-sizing: border-box;
        }
        .text-center { text-align: center; }
        .billing-logo {
            display: block;
            width: 12mm;
            height: auto;
            margin: 0 auto 3px;
            filter: brightness(0);
        }
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
            gap: 6px;
            margin-bottom: 2px;
        }
        .row > span:first-child { min-width: 0; overflow-wrap: anywhere; }
        .row > span:last-child { text-align: right; overflow-wrap: anywhere; min-width: 0; }
        .discount-row > span:last-child { flex-shrink: 0; white-space: nowrap; }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        .receipt-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin: 4px 0;
        }
        .receipt-table th {
            font-size: 12px;
            font-weight: bold;
            padding: 4px 0;
            border-bottom: 1px dashed #000;
        }
        .receipt-table td {
            font-size: 12px;
            padding: 3px 0;
            vertical-align: top;
        }
        .col-item {
            width: 44%;
            text-align: left;
            word-break: break-word;
            overflow-wrap: break-word;
            padding-right: 6px !important;
        }
        .col-qty {
            width: 12%;
            text-align: center;
            white-space: nowrap;
            padding-left: 2px !important;
            padding-right: 2px !important;
        }
        .col-price {
            width: 22%;
            text-align: right;
            white-space: nowrap;
            padding-left: 2px !important;
            padding-right: 6px !important;
        }
        .col-total {
            width: 22%;
            text-align: right;
            white-space: nowrap;
            padding-left: 6px !important;
            padding-right: 0 !important;
        }
        th, td {
            padding: 3px 0;
            font-size: 11px;
        }
        .no-print {
            margin-bottom: 16px;
            text-align: center;
        }
        .print-not-issued { display: none; }
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
            body { background-color: #fff; padding: 0; display: block; }
            .thermal-container { border: none; box-shadow: none; padding: 2mm 1mm; width: 72mm; max-width: 100%; margin: 0 auto; }
            .thermal-container, .thermal-container * { color: #000 !important; print-color-adjust: exact; -webkit-print-color-adjust: exact; }
            .no-print { display: none; }
            .thermal-container { display: none; }
            .billing-print-authorized .thermal-container { display: block; }
            .print-not-issued { display: block; }
            .billing-print-authorized .print-not-issued { display: none; }
        }
    </style>
</head>
<body @if($printRequested ?? false) class="billing-print-authorized" @endif>

    <div class="no-print">
        @if($errors->any())
            <div role="alert" style="max-width:72mm;margin:0 auto 12px;color:#991b1b;">
                <strong>Statement was not printed. Review the updated bill and try again.</strong>
                @foreach($errors->all() as $error)<p>{{ $error }}</p>@endforeach
            </div>
        @endif
        <form method="POST" action="{{ route('folios.billing.print', $folio) }}{{ request()->getQueryString() ? '?'.request()->getQueryString() : '' }}" style="display:inline">
            @csrf
            <input type="hidden" name="operation_key" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
            <button type="submit" class="btn-print">🖨️ Print 80mm Statement / Billing</button>
        </form>
        <button class="btn-print" style="background:#4b5563; margin-left:6px;" onclick="window.close()">Close</button>
        <p style="max-width:72mm;font-size:12px;">Each print request is recorded. Active stays can still be corrected, extended or converted forward.</p>
        @if(isset($receiptCopy) && !($printRequested ?? false))<p style="max-width:72mm;font-size:12px;">This request was already recorded as copy #{{ $receiptCopy }}. Use Print Statement / Billing for another tracked copy.</p>@endif
    </div>
    <p class="print-not-issued">Use the Print Statement / Billing button to issue this statement before printing.</p>

    <div class="thermal-container" id="printable-billing">
        <!-- Header -->
        <div class="text-center">
            <img class="billing-logo" src="{{ asset('billing-sedona-logo.png') }}" alt="Sedona Rose logo">
            <div class="font-bold" style="font-size: 14px; letter-spacing: 1px;">{{ config('sedona.hotel_name') }}</div>
            <div style="font-size: 10px;">{{ config('sedona.hotel_address') }}</div>
            <div style="font-size: 10px;">TEL: +63 (0939) 905-2816 &bull; TIN: 402-881-294-000</div>
        </div>

        <div class="double-divider"></div>
        <div class="text-center font-bold" style="font-size: 11px;">STATEMENT OF ACCOUNT / GUEST FOLIO</div>
        <div class="text-center" style="font-size: 10px; color: #000;">*** PRE-PRINT BILLING FOR ROOM DELIVERY ***</div>
        <div class="divider"></div>

        <!-- Meta -->
        <div class="row">
            <span>Folio Ref:</span>
            <span class="font-bold">{{ $folio->transaction_id }}</span>
        </div>
        @if($folio->receiptReference())
        <div class="row">
            <span>Receipt #:</span>
            <span class="font-bold">{{ $folio->receiptReference() }}</span>
        </div>
        @endif
        <div class="row">
            <span>Room Number:</span>
            <span class="font-bold">ROOM {{ $folio->room->number ?? 'N/A' }} ({{ $folio->room->type ?? '' }})</span>
        </div>
        <div class="row">
            <span>Check-In:</span>
            <span>{{ ($checkInTime ?? $folio->checked_in_at)->format('m/d/Y h:i A') }}</span>
        </div>
        <div class="row">
            <span>{{ $folio->isOpenTime() ? '3-hour base ends:' : 'Expected Out:' }}</span>
            <span>{{ ($expectedCheckoutAt ?? $folio->expected_checkout_at)->format('m/d/Y h:i A') }}</span>
        </div>
        <div class="row">
            <span>{{ $folio->isOpenTime() && $folio->isActive() ? 'Bill as of:' : 'Check-Out:' }}</span>
            <span>{{ ($checkOutTime ?? now())->format('m/d/Y h:i A') }}</span>
        </div>
        <div class="row">
            <span>Declared stay:</span>
            <span class="font-bold">{{ $folio->isOpenTime() ? 'Open Time (3-hour base)' : ($folio->rate_tier === 'promo' ? 'Midnight promo (6 AM checkout)' : $tierHours.' hours') }}</span>
        </div>
        @php
            $usedMinutes = intdiv(max(0, $checkOutTime->getTimestamp() - $checkInTime->getTimestamp()), 60);
        @endphp
        <div class="row">
            <span>Total time used:</span>
            <span class="font-bold">{{ intdiv($usedMinutes, 60) }}h {{ $usedMinutes % 60 }}m</span>
        </div>
        @if($excessHours > 0)
        <div class="row">
            <span>{{ $folio->isOpenTime() ? 'Additional Hours:' : 'Excess Overtime:' }}</span>
            <span class="font-bold">{{ $excessHours }} {{ \Illuminate\Support\Str::plural('Hour', $excessHours) }}</span>
        </div>
        @endif
        @if($xtendHours > 0)
        <div class="row">
            <span>Stay Extension:</span>
            <span class="font-bold">+{{ $xtendHours }} {{ \Illuminate\Support\Str::plural('Hour', $xtendHours) }}</span>
        </div>
        @endif
        <div class="row">
            <span>Bill Prepared:</span>
            <span>{{ ($preparedAt ?? now())->format('m/d/Y h:i A') }}</span>
        </div>

        <div class="divider"></div>
        <table class="receipt-table">
            <thead>
                <tr>
                    <th class="col-item">Item / Charge</th>
                    <th class="col-qty">Qty</th>
                    <th class="col-price">Price</th>
                    <th class="col-total">Total</th>
                </tr>
            </thead>
            <tbody>
                <tr>
                    <td class="col-item">{{ $folio->rate_tier === 'promo' ? 'Midnight Promo' : 'Room Base ('.strtoupper($folio->rate_tier).')' }}</td>
                    <td class="col-qty font-mono">1</td>
                    <td class="col-price font-mono">{{ number_format($roomRate, 2) }}</td>
                    <td class="col-total font-mono font-bold">{{ number_format($roomRate, 2) }}</td>
                </tr>
                @if($xtendHours > 0)
                <tr>
                    <td class="col-item">Stay Extension (+{{ $xtendHours }}h Xtend)</td>
                    <td class="col-qty font-mono">{{ $xtendHours }}</td>
                    <td class="col-price font-mono">130.00</td>
                    <td class="col-total font-mono font-bold">{{ number_format($xtendRate, 2) }}</td>
                </tr>
                @endif
                @if($excessHours > 0)
                <tr>
                    <td class="col-item">{{ $folio->isOpenTime() ? 'Additional Hours' : 'Excess Hours' }} (+{{ $excessHours }}h{{ $folio->isOpenTime() ? '' : ' Overstay' }})</td>
                    <td class="col-qty font-mono">{{ $excessHours }}</td>
                    <td class="col-price font-mono">130.00</td>
                    <td class="col-total font-mono font-bold">{{ number_format($excessRate, 2) }}</td>
                </tr>
                @endif
                @foreach($addOnItems as $item)
                <tr>
                    <td class="col-item">{{ $item['name'] }}</td>
                    <td class="col-qty font-mono">{{ $item['qty'] }}</td>
                    <td class="col-price font-mono">{{ number_format($item['price'], 2) }}</td>
                    <td class="col-total font-mono font-bold">{{ number_format($item['amount'], 2) }}</td>
                </tr>
                @endforeach
            </tbody>
        </table>
        <div class="divider"></div>
        <div class="row">
            <span>Gross Subtotal:</span>
            <span>₱{{ number_format($grossTotal, 2) }}</span>
        </div>

        @php
            $dType = $discountType ?? $folio->discount_type;
            $dRef = $discountIdRef ?? $folio->discount_id_ref;
        @endphp
        @if($totalDiscount > 0)
            <div class="row font-bold discount-row">
                <span>
                    Less: {{ $dType === 'DC' ? 'Sedona Discount Card' : ($dType === 'PWD' ? 'PWD Statutory Table' : 'Senior Statutory Table') }}{{ $dRef ? ' (' . $dRef . ')' : '' }}:
                </span>
                <span>-₱{{ number_format($totalDiscount, 2) }}</span>
            </div>
        @endif

        @if($deposit > 0)
            @if($applyDeposit)
                <div class="row font-bold">
                    <span>Less: Security Deposit Applied:</span>
                    <span>-₱{{ number_format($depositApplied, 2) }}</span>
                </div>
                @if($remainingDeposit > 0)
                    <div class="row font-bold">
                        <span>Remaining Deposit to Refund:</span>
                        <span>₱{{ number_format($remainingDeposit, 2) }}</span>
                    </div>
                @endif
            @else
                <div class="row">
                    <span>Security Deposit (Not Applied):</span>
                    <span>₱{{ number_format($deposit, 2) }}</span>
                </div>
                <div class="row font-bold">
                    <span>Remaining Deposit to Refund:</span>
                    <span>₱{{ number_format($remainingDeposit, 2) }}</span>
                </div>
            @endif
        @else
            <div class="row">
                <span>Less: Security Deposit:</span>
                <span>₱0.00</span>
            </div>
        @endif

        <div class="double-divider"></div>
        <div class="row font-bold" style="font-size: 13px;">
            <span>TOTAL AMOUNT DUE:</span>
            <span>₱{{ number_format($finalBalanceToPay, 2) }}</span>
        </div>
        <div class="double-divider"></div>

        <!-- Cashier Settlement & Payment Details -->
        @if(($paymentMethod && $paymentMethod !== 'cash') || $cashTendered > 0 || $gcashAmount > 0 || !empty($gcashRef) || ($folio->status === 'checked_out') || request()->has('payment_method'))
            <div class="row">
                <span>Payment Method:</span>
                <span class="font-bold">
                    @if($paymentMethod === 'split')
                        SPLIT (CASH + GCASH)
                    @elseif($paymentMethod === 'gcash')
                        GCASH E-WALLET
                    @else
                        CASH PAYMENT
                    @endif
                </span>
            </div>

            @if($paymentMethod === 'split')
                <div class="row">
                    <span>Cash Tendered:</span>
                    <span>₱{{ number_format($cashTendered, 2) }}</span>
                </div>
                <div class="row">
                    <span>GCash Amount Paid:</span>
                    <span>₱{{ number_format($gcashAmount, 2) }}</span>
                </div>
                @if(!empty($gcashRef))
                    <div class="row">
                        <span>GCash Reference #:</span>
                        <span>{{ $gcashRef }}</span>
                    </div>
                @endif
                <div class="row font-bold">
                    <span>Change Due to Guest:</span>
                    <span>₱{{ number_format($changeDue, 2) }}</span>
                </div>
            @elseif($paymentMethod === 'gcash')
                <div class="row">
                    <span>GCash Amount Paid:</span>
                    <span>₱{{ number_format($gcashAmount > 0 ? $gcashAmount : $finalBalanceToPay, 2) }}</span>
                </div>
                @if(!empty($gcashRef))
                    <div class="row">
                        <span>GCash Reference #:</span>
                        <span>{{ $gcashRef }}</span>
                    </div>
                @endif
            @else
                <div class="row">
                    <span>Cash Tendered:</span>
                    <span>₱{{ number_format($cashTendered, 2) }}</span>
                </div>
                <div class="row font-bold">
                    <span>Change Due to Guest:</span>
                    <span>₱{{ number_format($changeDue, 2) }}</span>
                </div>
            @endif
            <div class="double-divider"></div>
        @endif

        <div class="divider"></div>

        <div class="text-center" style="font-size: 10px; margin-top: 10px; color: #333;">
            <div>Please review charges before checkout.</div>
            <div>Settle your balance at Frontdesk Reception.</div>
            <div style="margin-top: 4px; font-size: 10px; color: #000;">
                "Interim Billing Folio &bull; {{ config('sedona.hotel_name') }}"
            </div>
        </div>
    </div>

</body>
@if($printRequested ?? false)
<script>
window.addEventListener('afterprint', () => document.body.classList.remove('billing-print-authorized'));
window.addEventListener('load', () => window.print());
</script>
@endif
</html>
