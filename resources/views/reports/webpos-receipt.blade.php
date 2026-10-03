<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Receipt</title>
    <style>
        @page { margin: 14px 0; }
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; margin: 0; }
        p { text-align: center; margin: 8px 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 2px 6px; vertical-align: top; }
        .row td { border-bottom: 1px dashed #888; }
        .right { text-align: right; }
        .foot { font-family: monospace; font-style: italic; font-size: 7px; text-align: center; margin-top: 18px; }
    </style>
</head>
<body>
    <p style="font-size: 14px; font-weight: bold;">SUNCASH</p>
    <p>
        @if ($r['vat_layout'])VAT Sales Receipt/Invoice<br>TIN: 103973476<br>@endif
        <b>{{ $r['location'] }}</b><br>
        {{ $r['address'] }}<br>
        {{ $r['island'] }}, Bahamas<br>
        Merchant-ID#{{ $r['merchant_id'] }}
    </p>
    <p>CASHIER NAME<br>{{ $r['cashier'] }}</p>

    <table>
        <tr><td width="45%">TRANSACTION ID</td><td width="55%">{{ $r['transaction_id'] }}</td></tr>
        <tr><td>TRANSACTION DATE</td><td>{{ $r['date'] }}</td></tr>
        <tr><td>TRANSACTION TYPE</td><td>{{ $r['type_text'] }}</td></tr>
    </table>

    @if (($r['section'] ?? '') === 'none' && $r['type_text'] === 'BILLPAY')
        <table>
            <tr><td width="45%">Biller Name</td><td>{{ $r['biller_name'] }}</td></tr>
            <tr><td>Account Name</td><td>{{ $r['customer_name'] }}</td></tr>
            <tr><td>Account #</td><td>{{ $r['biller_account'] }}</td></tr>
        </table>
    @endif

    <table style="margin-top: 6px;">
        <tr class="row"><td width="40%">Summary</td><td></td></tr>
        <tr class="row"><td>AMOUNT</td><td class="right">{{ number_format($r['amount'], 2) }}</td></tr>
        <tr class="row"><td>TRANSACTION FEE</td><td class="right">{{ number_format($r['fee'], 2) }}</td></tr>
        @if ($r['show_vat'])
            <tr class="row"><td>VAT</td><td class="right">{{ number_format($r['vat'], 2) }}</td></tr>
        @endif
        <tr><td class="right"><b>TOTAL AMOUNT</b></td><td class="right"><b>{{ number_format($r['total'], 2) }}</b></td></tr>
    </table>

    @switch($r['section'])
        @case('customer')
            <p>{{ $r['customer_label'] }} DETAILS<br>
                @if ($r['anonymous'])
                    <b>Anonymous</b>
                @else
                    @if ($r['customer_name'] !== 'NA')<b>{{ $r['customer_name'] }}</b><br>@endif
                    <b>{{ $r['customer_mobile_fmt'] }}</b>
                @endif
            </p>
            @break
        @case('sender_receiver')
            <p>SENDER DETAILS<br><b>{{ $r['customer_name'] }}</b><br><b>{{ $r['customer_mobile_fmt'] }}</b></p>
            <p>RECEIVER DETAILS<br><b>{{ $r['receiver_name'] }}</b><br><b>{{ $r['receiver_mobile_fmt'] }}</b></p>
            @break
        @case('bank')
            <p>CUSTOMER DETAILS<br><b>{{ $r['customer_name'] }}</b><br><b>{{ $r['customer_mobile_fmt'] }}</b></p>
            <p>BANK DETAILS<br><b>{{ $r['biller_account'] }}</b><br><b>{{ $r['receiver_name'] }}</b><br><b>{{ $r['receiver_mobile'] }}</b></p>
            @break
        @case('government')
            <p>CUSTOMER DETAILS<br><b>{{ $r['customer_name'] }}</b><br><b>{{ $r['customer_mobile_fmt'] }}</b><br>
                REFERENCE CODE<br><b>{{ $r['extra']['reference_code'] ?? '' }}</b><br>
                INVOICE NUMBER<br><b>{{ $r['extra']['invoice_number'] ?? '' }}</b><br>
                INVOICE DETAILS<br><b>{{ $r['biller_account'] }}</b><br><b>{{ $r['extra']['branch_name'] ?? '' }}</b><br><b>{{ $r['biller_name'] }}</b></p>
            @break
    @endswitch

    <p>PAYMENT METHOD<br>CASH</p>
    <div class="foot">2017 Suncash Merchant Admin.</div>
</body>
</html>
