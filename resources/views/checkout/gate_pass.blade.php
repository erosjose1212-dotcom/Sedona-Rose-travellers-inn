<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Gate Pass - Room {{ $folio->room->number ?? 'N/A' }}</title>
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
        .pass-container {
            width: 76mm;
            max-width: 100%;
            background-color: #fff;
            border: 1px solid #ddd;
            box-shadow: 0 4px 10px rgba(0,0,0,0.08);
            padding: 14px 12px;
            position: relative;
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
        .banner {
            background-color: #000;
            color: #fff;
            padding: 4px 0;
            text-align: center;
            font-weight: 900;
            font-size: 13px;
            letter-spacing: 2px;
            margin: 6px 0;
        }
        .row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 3px;
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
            body {
                background-color: #fff;
                padding: 0;
            }
            .pass-container {
                border: none;
                box-shadow: none;
                padding: 4px;
                width: 100%;
            }
            .no-print { display: none; }
        }
    </style>
</head>
<body>

    <div class="no-print">
        <button class="btn-print" onclick="window.print()">🖨️ Print 80mm Gate Pass</button>
        <button class="btn-print" style="background:#4b5563; margin-left:6px;" onclick="window.close()">Close Window</button>
    </div>

    <div class="pass-container" id="printable-gate-pass">
        <!-- Header Brand -->
        <div class="text-center">
            <div class="font-bold" style="font-size: 14px; letter-spacing: 1px;">{{ config('sedona.hotel_name') }}</div>
            <div style="font-size: 9px; margin-top: 2px;">DOÑA REMEDIOS TRINIDAD HWY</div>
            <div style="font-size: 9px;">{{ config('sedona.hotel_address') }}</div>
            <div style="font-size: 9px;">TEL: +63 (0939) 905-2816</div>
        </div>

        <div class="double-divider"></div>

        <!-- Prominent GATE PASS Banner -->
        <div class="banner">GATE PASS</div>

        <!-- Primary Stay Information -->
        <div style="margin-top: 6px;">
            <div class="row font-bold">
                <span>Ticket No.:</span>
                <span>{{ $ticketNo }}</span>
            </div>
            <div class="row font-bold" style="font-size: 13px;">
                <span>Room No.:</span>
                <span>ROOM {{ $folio->room->number ?? 'N/A' }} ({{ $folio->room->type ?? '' }})</span>
            </div>
            <div class="row">
                <span>Check-In:</span>
                <span>{{ $folio->checked_in_at->format('m/d/Y h:i A') }}</span>
            </div>
            <div class="row">
                <span>Check-Out:</span>
                <span>{{ ($folio->checked_out_at ?? now())->format('m/d/Y h:i A') }}</span>
            </div>
        </div>

        <div class="divider"></div>

        <!-- Secondary Details -->
        <div>
            <div class="row">
                <span>Guest Name:</span>
                <span class="font-bold">{{ $folio->guest->name ?? 'Walk-In Guest' }}</span>
            </div>
            <div class="row">
                <span>Plate / Vehicle:</span>
                <span class="font-bold">{{ $folio->guest->plate_number ?? ($folio->notes ?? 'N/A') }}</span>
            </div>
            <div class="row">
                <span>Cashier Operator:</span>
                <span>{{ $folio->cashier->name ?? 'Frontdesk Staff' }} // FD-01</span>
            </div>
            <div class="row font-bold" style="margin-top: 4px;">
                <span>Pass Status:</span>
                <span style="letter-spacing: 1px;">CLEARED FOR EXIT</span>
            </div>
        </div>

        <div class="divider"></div>

        <div style="margin-top: 10px; text-align: center; font-size: 9px; font-style: italic; color: #444;">
            <div>Please surrender this pass to the gate security.</div>
            <div>Thank you for choosing {{ config('sedona.hotel_name') }}!</div>
        </div>
    </div>

</body>
</html>
