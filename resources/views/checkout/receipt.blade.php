<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Settlement Receipt - {{ $folio->receiptReference() ?? 'Not issued' }}</title>
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
        .receipt-logo {
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
        .no-print {
            margin-bottom: 16px;
            text-align: center;
        }
        .btn-print {
            background-color: #421A2B;
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
        }
    </style>
</head>
<body>

    <div class="no-print">
        @if(!$folio->isActive())
        <form method="POST" action="{{ route('folios.receipt.print', $folio) }}" style="display: inline;">
            @csrf
            <input type="hidden" name="operation_key" value="{{ \Illuminate\Support\Str::uuid() }}">
            <button class="btn-print">🖨️ Request receipt print / copy</button>
        </form>
        @else
        <button class="btn-print" onclick="window.print()">Print bill preview</button>
        @endif
        <button class="btn-print" style="background:#4b5563; margin-left:6px;" onclick="window.close()">Close</button>
    </div>

    <div class="thermal-container" id="printable-receipt">
        <div class="text-center" style="font-size: 10px; font-weight: bold;">{{ isset($receiptCopy) ? ($receiptCopy === 1 ? 'FIRST RECORDED PRINT REQUEST' : 'COPY — PRINT REQUEST #'.$receiptCopy) : 'PREVIEW — NO PRINT REQUEST RECORDED' }}</div>
        <div class="text-center">
            <img class="receipt-logo" src="{{ asset('billing-sedona-logo.png') }}" alt="Sedona Rose logo">
            <div class="font-bold" style="font-size: 14px; letter-spacing: 1px;">{{ config('sedona.hotel_name') }}</div>
            <div style="font-size: 10px;">{{ config('sedona.hotel_address') }}</div>
            <div style="font-size: 10px;">TEL: +63 (0939) 905-2816</div>
        </div>

        <div class="double-divider"></div>
        <div class="text-center font-bold" style="font-size: 11px;">{{ $folio->isActive() ? 'DRAFT BILL - PAYMENT PENDING' : ($folio->force_by ? 'WRITE-OFF RECORD' : 'SETTLEMENT RECEIPT') }}</div>
        <div class="divider"></div>

        <div class="row">
            <span>Receipt #:</span>
            <span class="font-bold">{{ $folio->receiptReference() ?? 'Not issued' }}</span>
        </div>
        <div class="row">
            <span>Room Assigned:</span>
            <span class="font-bold">ROOM {{ $folio->room->number ?? 'N/A' }} ({{ $folio->room->type ?? '' }})</span>
        </div>
        <div class="row">
            <span>Check-In:</span>
            <span>{{ $folio->checked_in_at->format('m/d/Y h:i A') }}</span>
        </div>
        <div class="row">
            <span>{{ $folio->isOpenTime() && $folio->isActive() ? 'Bill as of:' : 'Check-Out:' }}</span>
            <span>{{ $checkOutTime->format('m/d/Y h:i A') }}</span>
        </div>
        <div class="row">
            <span>Declared stay:</span>
            <span class="font-bold">{{ $folio->isOpenTime() ? 'Open Time (3-hour base)' : ($folio->rate_tier === 'promo' ? 'Midnight promo (6 AM checkout)' : $tierHours.' hours') }}</span>
        </div>
        @if($xtendHours > 0)
        <div class="row">
            <span>Added extension:</span>
            <span class="font-bold">+{{ $xtendHours }} {{ $xtendHours === 1 ? 'hour' : 'hours' }}</span>
        </div>
        @endif
        @php
            $usedMinutes = intdiv(max(0, $checkOutTime->getTimestamp() - $checkInTime->getTimestamp()), 60);
        @endphp
        <div class="row">
            <span>Total time used:</span>
            <span class="font-bold">{{ intdiv($usedMinutes, 60) }}h {{ $usedMinutes % 60 }}m</span>
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
                    <td class="col-item">{{ $folio->rate_tier === 'promo' ? 'Midnight Promo' : 'Room Base ('.$tierHours.'h)' }}</td>
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
            <span>₱{{ number_format($grossTotal ?? $folio->gross_total, 2) }}</span>
        </div>

        @php
            $disc = $totalDiscount ?? $folio->discount_amount;
            $dType = $discountType ?? $folio->discount_type;
            $dRef = $discountIdRef ?? $folio->discount_id_ref;
            $dep = $deposit ?? (float)($folio->security_deposit ?? 0);
        @endphp
        @if($disc > 0)
            <div class="row font-bold discount-row">
                <span>
                    Less: {{ $dType === 'DC' ? 'Sedona Discount Card' : ($dType === 'PWD' ? 'PWD Statutory Table' : 'Senior Statutory Table') }}{{ $dRef ? ' (' . $dRef . ')' : '' }}:
                </span>
                <span>-₱{{ number_format($disc, 2) }}</span>
            </div>
        @endif

        @if($dep > 0)
            @if($applyDeposit ?? ($folio->deposit_status !== 'not_applied'))
                <div class="row font-bold">
                    <span>Less: Security Deposit Applied:</span>
                    <span>-₱{{ number_format($depositApplied ?? min($dep, $disc > 0 ? max(0, ($grossTotal ?? $folio->gross_total) - $disc) : ($grossTotal ?? $folio->gross_total)), 2) }}</span>
                </div>
                @if(($remainingDeposit ?? 0) > 0)
                    <div class="row font-bold">
                        <span>Remaining Deposit to Refund:</span>
                        <span>₱{{ number_format($remainingDeposit, 2) }}</span>
                    </div>
                @endif
            @else
                <div class="row">
                    <span>Security Deposit (Not Applied):</span>
                    <span>₱{{ number_format($dep, 2) }}</span>
                </div>
                <div class="row font-bold">
                    <span>Remaining Deposit to Refund:</span>
                    <span>₱{{ number_format($remainingDeposit ?? $dep, 2) }}</span>
                </div>
            @endif
        @endif

        <div class="double-divider"></div>
        <div class="row font-bold" style="font-size: 13px;">
            <span>NET AMOUNT DUE:</span>
            <span>₱{{ number_format($finalBalanceToPay ?? $folio->net_total, 2) }}</span>
        </div>
        <div class="divider"></div>

        <div class="row">
            <span>{{ $folio->force_by ? 'Resolution:' : 'Payment Method:' }}</span>
            <span class="font-bold">
                @if($folio->force_by)
                    APPROVED WRITE-OFF
                @elseif(($receiptPaymentMethod ?? $folio->payment_method ?? '') === 'split')
                    SPLIT (CASH + GCASH)
                @elseif(($receiptPaymentMethod ?? $folio->payment_method ?? '') === 'gcash')
                    GCASH E-WALLET
                @else
                    CASH PAYMENT
                @endif
            </span>
        </div>

        @include('checkout.deposit_payments', ['depositPayments' => $depositPayments ?? []])

        @if(($depositApplied ?? 0) > 0 && ($finalBalanceToPay ?? $folio->net_total) > 0)
            <div class="row font-bold"><span>Additional checkout payment:</span></div>
        @endif
        @if(!$folio->force_by && ($depositApplied ?? 0) > 0 && ($finalBalanceToPay ?? $folio->net_total) <= 0)
            <div class="row"><span>Paid in full by deposit.</span></div>
        @elseif(($folio->payment_method ?? '') === 'split')
            <div class="row">
                <span>Cash Tendered:</span>
                <span>₱{{ number_format($folio->cash_tendered, 2) }}</span>
            </div>
            <div class="row">
                <span>GCash Amount Paid:</span>
                <span>₱{{ number_format($folio->gcash_amount, 2) }}</span>
            </div>
            @if(!empty($folio->gcash_reference))
                <div class="row">
                    <span>GCash Ref #:</span>
                    <span>{{ $folio->gcash_reference }}</span>
                </div>
            @endif
            <div class="row font-bold">
                <span>Change Returned:</span>
                <span>₱{{ number_format($folio->change_due, 2) }}</span>
            </div>
        @elseif(($folio->payment_method ?? '') === 'gcash')
            <div class="row">
                <span>GCash Amount Paid:</span>
                <span>₱{{ number_format($folio->gcash_amount > 0 ? $folio->gcash_amount : ($finalBalanceToPay ?? $folio->net_total), 2) }}</span>
            </div>
            @if(!empty($folio->gcash_reference))
                <div class="row">
                    <span>GCash Ref #:</span>
                    <span>{{ $folio->gcash_reference }}</span>
                </div>
            @endif
        @else
            <div class="row">
                <span>Cash Tendered:</span>
                <span>₱{{ number_format($folio->cash_tendered, 2) }}</span>
            </div>
            <div class="row font-bold">
                <span>Change Returned:</span>
                <span>₱{{ number_format($folio->change_due, 2) }}</span>
            </div>
        @endif

        <div class="double-divider"></div>

        <div class="text-center" style="font-size: 10px; margin-top: 10px;">
            <div>Attending Cashier: <strong>{{ $folio->checkedOutBy->name ?? $folio->cashier->name ?? 'Front Desk Staff' }} // FD-01</strong></div>
            <div style="margin-top: 4px; font-weight: bold;">Thank you for choosing {{ config('sedona.hotel_name') }}!</div>
            <div style="font-size: 10px; color: #000;">Please present your Exit Gate Pass upon vehicle departure.</div>
            <div style="margin-top: 6px; font-size: 10px; color: #000;">"This document serves as an Official Settlement Slip"</div>
        </div>

        <div class="no-print" style="margin-top: 16px; border-top: 1px dashed #ccc; padding-top: 12px; text-align: center;">
            <a href="{{ route('folios.gate_pass', $folio) }}" target="_blank" class="btn-print" style="background: #1e3a8a; text-decoration: none; display: inline-block;">
                🎫 Print Exit Gate Pass
            </a>
        </div>
    </div>

</body>
@if($printRequested ?? false)
<script>window.addEventListener('load', () => window.print());</script>
@endif
</html>
