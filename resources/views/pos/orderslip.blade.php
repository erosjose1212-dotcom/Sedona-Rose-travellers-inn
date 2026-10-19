<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Order Slip - Room {{ $folio->room->number ?? '' }}</title>
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
        .font-bold { font-weight: bold; }
        .divider { border-top: 1px dashed #000; margin: 6px 0; }
        .double-divider { border-top: 2px solid #000; margin: 6px 0; }
        .row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 2px;
        }
        table { width: 100%; border-collapse: collapse; }
        .receipt-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            margin: 4px 0;
        }
        .receipt-table th {
            font-size: 11px;
            font-weight: bold;
            padding: 4px 0;
            border-bottom: 1px dashed #000;
        }
        .receipt-table td {
            font-size: 11px;
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
        th, td { padding: 3px 0; font-size: 11px; }
        .btn-print {
            background-color: #0275d8;
            color: #fff;
            padding: 8px 16px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-weight: bold;
            font-size: 12px;
        }
        .no-print {
            margin-bottom: 16px;
            text-align: center;
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
        <button onclick="window.print()" class="btn-print">🖨️ Print 80mm Order Slip</button>
        <button onclick="window.close()" class="btn-print" style="background:#4b5563; margin-left:6px;">Close</button>
    </div>

    <div class="thermal-container" id="printable-order-slip">
        <div class="text-center">
            <div class="font-bold" style="font-size: 14px; letter-spacing: 1px;">{{ config('sedona.hotel_name') }}</div>
            <div style="font-size: 9px;">KITCHEN, BAR &amp; AMENITY SALES</div>
            <div style="font-size: 9px;">Doña Remedios Trinidad Hwy, San Rafael, Bulacan</div>
            <div style="font-size: 9px;">TEL: +63 (0939) 905-2816</div>
        </div>

        <div class="double-divider"></div>
        <div class="text-center font-bold" style="font-size: 11px;">GUEST ORDER SLIP / FOLIO CHARGES (FOOD, BEVERAGE &amp; AMENITIES)</div>
        <div class="divider"></div>

        <div>
            <div class="row">
                <span>Order Slip Ref:</span>
                <span class="font-bold">ORD-{{ $folio->transaction_id }}</span>
            </div>
            <div class="row">
                <span>Room Assigned:</span>
                <span class="font-bold">ROOM {{ $folio->room->number ?? 'N/A' }} ({{ $folio->room->type ?? '' }})</span>
            </div>
            <div class="row">
                <span>Guest / Order For:</span>
                <span>{{ $folio->guest->name ?? 'Walk-In Guest' }}</span>
            </div>
            <div class="row">
                <span>Date &amp; Time:</span>
                <span>{{ now()->format('m/d/Y h:i A') }}</span>
            </div>
            <div class="row">
                <span>Order Type:</span>
                <span class="font-bold">Room Add-On / POS Dining</span>
            </div>
        </div>

        <div class="divider"></div>

        @php
            $posItemsList = $folio->pos_items ?? [];
            $posTotalAmount = collect($posItemsList)->sum(fn($i) => (float)($i['subtotal'] ?? 0));
        @endphp

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
                @forelse($posItemsList as $item)
                    <tr>
                        <td class="col-item">{{ $item['name'] }}</td>
                        <td class="col-qty font-mono">{{ $item['qty'] }}</td>
                        <td class="col-price font-mono">{{ number_format($item['price'], 2) }}</td>
                        <td class="col-total font-mono font-bold">{{ number_format($item['subtotal'], 2) }}</td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="4" class="text-center" style="padding: 10px 0; color: #666;">
                            No food, beverage, or amenity items ordered.
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>

        <div class="divider"></div>

        <div class="double-divider"></div>
        <div class="row font-bold" style="font-size: 13px;">
            <span>TOTAL ORDER ITEMS:</span>
            <span>₱{{ number_format($posTotalAmount, 2) }}</span>
        </div>
        <div class="double-divider"></div>

        <!-- Scannable Code 128 Barcode -->
        <div style="text-align: center; margin-top: 8px;">
            <div style="height: 34px; width: 85%; margin: 0 auto;">
                {!! \App\Services\BarcodeService::renderSvg('ORD-' . $folio->transaction_id, 34) !!}
            </div>
            <div style="font-size: 9px; letter-spacing: 2px; font-weight: bold; margin-top: 3px;">
                *ORD-{{ $folio->transaction_id }}*
            </div>
        </div>

        <div class="text-center" style="font-size: 9px; margin-top: 10px; color: #444;">
            <div>Thank you for dining with {{ config('sedona.hotel_name') }}!</div>
            <div>Order charged to Room {{ $folio->room->number ?? 'N/A' }} Folio.</div>
        </div>
    </div>

</body>
</html>
