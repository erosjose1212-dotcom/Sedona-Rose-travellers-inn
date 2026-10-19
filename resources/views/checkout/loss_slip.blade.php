<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Audited Loss Slip - {{ $folio->transaction_id }}</title>
    <style>
        @page {
            size: letter portrait;
            margin: 15mm;
        }
        body {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 12px;
            line-height: 1.4;
            color: #111;
            background-color: #fff;
            margin: 0;
            padding: 20px;
        }
        .container {
            max-width: 650px;
            margin: 0 auto;
            border: 2px solid #374151;
            padding: 24px;
        }
        .header {
            text-align: center;
            border-bottom: 2px solid #374151;
            padding-bottom: 12px;
            margin-bottom: 16px;
        }
        .brand {
            font-size: 18px;
            font-weight: 900;
            color: #1f2937;
            letter-spacing: 1px;
        }
        .title {
            font-size: 14px;
            font-weight: bold;
            color: #b91c1c;
            margin-top: 4px;
            text-transform: uppercase;
        }
        .info-grid {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 12px;
            margin-bottom: 16px;
            font-size: 12px;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 4px 0;
            border-bottom: 1px dotted #ccc;
        }
        .loss-box {
            background-color: #fef2f2;
            border: 2px solid #ef4444;
            padding: 12px 16px;
            margin: 16px 0;
            text-align: center;
        }
        .loss-amount {
            font-size: 22px;
            font-weight: bold;
            color: #b91c1c;
            font-family: monospace;
        }
        .reason-box {
            background: #f9fafb;
            border: 1px solid #d1d5db;
            padding: 10px;
            margin: 12px 0;
            font-size: 12px;
        }
        .signatures {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 32px;
            margin-top: 40px;
            text-align: center;
        }
        .sig-line {
            border-top: 1px solid #000;
            margin-top: 40px;
            padding-top: 4px;
            font-weight: bold;
            font-size: 11px;
        }
        .no-print {
            text-align: center;
            margin-bottom: 16px;
        }
        .btn-print {
            background-color: #374151;
            color: #fff;
            padding: 8px 18px;
            font-size: 13px;
            font-weight: bold;
            border: none;
            cursor: pointer;
            border-radius: 4px;
        }
        @media print {
            .no-print { display: none; }
            body { padding: 0; }
        }
    </style>
</head>
<body>

    <div class="no-print">
        <button class="btn-print" onclick="window.print()">Print Audited Loss Slip</button>
        <button class="btn-print" style="background:#4b5563;" onclick="window.close()">Close</button>
    </div>

    <div class="container">
        <div class="header">
            <div class="brand">{{ config('sedona.hotel_name') }}</div>
            <div style="font-size: 11px; color: #444;">Executive Accounting & Loss Control Audit Journal</div>
            <div class="title">FORCE CHECKOUT WRITE-OFF LOSS SLIP</div>
        </div>

        <div class="info-grid">
            <div>
                <div class="info-row">
                    <span>Loss Slip Ref:</span>
                    <strong style="font-family: monospace; color: #b91c1c;">{{ $folio->loss_slip_reference }}</strong>
                </div>
                <div class="info-row">
                    <span>Folio Number:</span>
                    <strong>{{ $folio->transaction_id }}</strong>
                </div>
                <div class="info-row">
                    <span>Room Number:</span>
                    <strong>Room {{ $folio->room->number ?? 'N/A' }}</strong>
                </div>
            </div>
            <div>
                <div class="info-row">
                    <span>Date Written Off:</span>
                    <span>{{ ($folio->checked_out_at ?? now())->format('m/d/Y h:i A') }}</span>
                </div>
                <div class="info-row">
                    <span>Guest Name:</span>
                    <span>{{ $folio->guest->name ?? 'Guest' }}</span>
                </div>
                <div class="info-row">
                    <span>Requesting Cashier:</span>
                    <span>{{ $folio->cashier->name ?? 'Front Desk Staff' }}</span>
                </div>
            </div>
        </div>

        <div class="loss-box">
            <div style="font-size: 11px; font-weight: bold; text-transform: uppercase; color: #7f1d1d;">Audited Uncollected Bad Debt Amount</div>
            <div class="loss-amount">₱{{ number_format($folio->net_total, 2) }}</div>
            <div style="font-size: 10px; color: #7f1d1d; margin-top: 2px;">Transferred to Property Operating Bad Debt Allowance</div>
        </div>

        <div class="reason-box">
            <strong>INCIDENT REASON & WRITE-OFF JUSTIFICATION:</strong>
            <div style="margin-top: 4px; font-style: italic; color: #374151;">
                "{{ $folio->force_reason ?? 'Guest skipped out without settling account balance.' }}"
            </div>
        </div>

        <div class="signatures">
            <div>
                <div class="sig-line">Requesting Cashier Signature</div>
                <div style="font-size: 10px; color: #666;">{{ $folio->cashier->name ?? 'Duty Cashier' }}</div>
            </div>
            <div>
                <div class="sig-line">Executive / Admin Approval Signature</div>
                <div style="font-size: 10px; color: #666;">{{ $folio->forcedBy->name ?? 'Managing Administrator' }}</div>
            </div>
        </div>
    </div>

</body>
</html>
