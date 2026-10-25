<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Order Slip - Room {{ $folio->room->number ?? '' }}</title>
    <style>
        @page { size: 80mm auto; margin: 0; }
        body { margin: 0; padding: 12px 8px; background: #f5f5f0; color: #000; font: 12px/1.2 'Courier New', monospace; }
        .thermal-container { box-sizing: border-box; width: 72mm; max-width: 100%; margin: auto; padding: 8px; background: #fff; }
        .text-center { text-align: center; }
        .font-bold, strong { font-weight: 700; }
        .row { display: flex; justify-content: space-between; gap: 6px; margin: 2px 0; overflow-wrap: anywhere; }
        .divider { border-top: 1px dashed #000; margin: 4px 0; }
        .no-print { text-align: center; margin-bottom: 12px; }
        button { border: 0; border-radius: 4px; padding: 8px 12px; background: #421a2b; color: #fff; cursor: pointer; }
        @media print { body { padding: 0; background: #fff; } .thermal-container { padding: 2mm; } .no-print { display: none; } }

        .receipt-table { width: 100%; border-collapse: collapse; table-layout: fixed; }
        th, td { padding: 2px 0; vertical-align: top; }
        th { border-bottom: 1px dashed #000; }
        .col-item { width: 46%; text-align: left; overflow-wrap: anywhere; padding-right: 6px; }
        .col-qty { width: 10%; text-align: center; }
        .col-price { width: 21%; text-align: right; white-space: nowrap; padding-left: 6px; }
        .col-total { width: 23%; text-align: right; white-space: nowrap; padding-left: 6px; }
        .large-order-line { display: flex; flex-wrap: wrap; justify-content: space-between; gap: 4px 8px; }
        .large-order-line strong { margin-left: auto; white-space: nowrap; }
    </style>
</head>
<body>
    <div class="no-print"><button onclick="window.print()">Print 80mm Order Slip</button> <button onclick="window.close()">Close</button></div>
    <div class="thermal-container" id="printable-order-slip">
        <div class="text-center font-bold">{{ config('sedona.hotel_name') }}</div>
        <div class="row"><strong>ORDER SLIP · ROOM {{ $folio->room->number ?? 'N/A' }}</strong></div>
        <div>ORD-{{ $folio->transaction_id }}</div>
        <div>{{ now()->format('m/d/Y h:i A') }}</div>
        <div class="divider"></div>
        @php
            $posItemsList = $folio->pos_items ?? [];
            $posTotalAmount = collect($posItemsList)->sum(fn ($item) => (float) ($item['subtotal'] ?? 0));
        @endphp
        <table class="receipt-table">
            <thead><tr><th class="col-item">Item</th><th class="col-qty">Qty</th><th class="col-price">Price</th><th class="col-total">Total</th></tr></thead>
            <tbody>@forelse($posItemsList as $item)
                @if(strlen((string) $item['qty']) > 3 || strlen(number_format($item['price'], 2)) > 6 || strlen(number_format($item['subtotal'], 2)) > 7)
                <tr><td colspan="4"><div>{{ $item['name'] }}</div><div class="large-order-line"><span>{{ $item['qty'] }} × {{ number_format($item['price'], 2) }}</span><strong>{{ number_format($item['subtotal'], 2) }}</strong></div></td></tr>
                @else
                <tr><td class="col-item">{{ $item['name'] }}</td><td class="col-qty">{{ $item['qty'] }}</td><td class="col-price">{{ number_format($item['price'], 2) }}</td><td class="col-total font-bold">{{ number_format($item['subtotal'], 2) }}</td></tr>
                @endif
            @empty<tr><td colspan="4">No items ordered.</td></tr>@endforelse</tbody>
        </table>
        <div class="divider"></div>
        <div class="row font-bold"><span>TOTAL ORDER ITEMS:</span><span>₱{{ number_format($posTotalAmount, 2) }}</span></div>
    </div>
</body>
</html>
