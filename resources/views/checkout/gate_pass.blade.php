<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gate Pass - Room {{ $folio->room->number ?? 'N/A' }}</title>
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
</style>
</head>
<body>
    <div class="no-print"><button onclick="window.print()">Print 80mm Gate Pass</button> <button onclick="window.close()">Close</button></div>
    <div class="thermal-container" id="printable-gate-pass">
        <div class="text-center font-bold">{{ config('sedona.hotel_name') }}</div>
        <div class="text-center font-bold">GATE PASS · CLEARED FOR EXIT</div>
        <div class="divider"></div>
        <div class="row"><strong>ROOM {{ $folio->room->number ?? 'N/A' }}</strong></div>
        <div class="row"><span>Ticket:</span><strong>{{ $ticketNo }}</strong></div>
        <div class="row"><span>In:</span><span>{{ $folio->checked_in_at->format('m/d/Y h:i A') }}</span></div>
        <div class="row"><span>Out:</span><span>{{ ($folio->checked_out_at ?? now())->format('m/d/Y h:i A') }}</span></div>
        <div class="row"><span>Cashier:</span><span>{{ $folio->checkedOutBy->name ?? $folio->cashier->name ?? 'Frontdesk' }}</span></div>
        <div class="divider"></div>
        <div class="text-center">Surrender to gate security.</div>
    </div>
</body>
</html>
