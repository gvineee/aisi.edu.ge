<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="utf-8">
    <style>
        @font-face { font-family: 'NotoGeorgian'; font-weight: normal; src: url('file://{{ str_replace('\\', '/', $fontRegular) }}') format('truetype'); }
        @font-face { font-family: 'NotoGeorgian'; font-weight: bold; src: url('file://{{ str_replace('\\', '/', $fontBold) }}') format('truetype'); }
        body { font-family: 'NotoGeorgian', sans-serif; font-size: 10.5pt; color: #132B45; }
        h1 { font-size: 17pt; margin: 0 0 4pt; }
        .muted { color: #555; font-size: 9pt; }
        table { width: 100%; border-collapse: collapse; margin-top: 10pt; }
        th, td { border: 1px solid #c9ced6; padding: 5pt 6pt; text-align: left; vertical-align: top; }
        th { background: #f1f3f6; font-weight: bold; }
        .num { text-align: right; white-space: nowrap; }
        .box { margin-top: 14pt; border: 1px solid #c9ced6; padding: 8pt; }
        .void { color: #a11; font-weight: bold; font-size: 13pt; }
    </style>
</head>
<body>
    <p class="muted">{{ $schoolName }}</p>
    <h1>ინვოისი № {{ $invoice['number'] }}</h1>
    @if ($invoice['status'] === 'void')
        <p class="void">გაუქმებულია{{ $invoice['voidReason'] ? ' — '.$invoice['voidReason'] : '' }}</p>
    @endif
    <p>
        მოსწავლე: <strong>{{ $invoice['studentName'] }}</strong>@if ($invoice['className']) · {{ $invoice['className'] }}@endif<br>
        გამოწერის თარიღი: {{ $invoice['issuedOn'] }} · გადახდის ვადა: <strong>{{ $invoice['dueOn'] }}</strong>
    </p>

    <table>
        <thead><tr><th>აღწერა</th><th class="num">რაოდ.</th><th class="num">ფასი</th><th class="num">თანხა</th></tr></thead>
        <tbody>
            @foreach ($invoice['items'] as $item)
                <tr>
                    <td>{{ $item['description'] }}</td>
                    <td class="num">{{ $item['quantity'] }}</td>
                    <td class="num">{{ $item['unit'] }}</td>
                    <td class="num">{{ $item['amount'] }}</td>
                </tr>
            @endforeach
            <tr><th colspan="3">სულ</th><th class="num">{{ $invoice['total'] }}</th></tr>
            <tr><td colspan="3">გადახდილი</td><td class="num">{{ $invoice['paid'] }}</td></tr>
            <tr><th colspan="3">დარჩენილი</th><th class="num">{{ $invoice['outstanding'] }}</th></tr>
        </tbody>
    </table>

    @if ($settings)
        <div class="box">
            <strong>გადახდის რეკვიზიტები</strong><br>
            @if ($settings['recipientName']) მიმღები: {{ $settings['recipientName'] }}@if ($settings['recipientCode']) (ს/კ {{ $settings['recipientCode'] }})@endif<br>@endif
            @foreach ($settings['bankAccounts'] as $account)
                {{ $account['bank'] }}: {{ $account['iban'] }}<br>
            @endforeach
            დანიშნულება: ინვოისი № {{ $invoice['number'] }}, {{ $invoice['studentName'] }}
            @if ($settings['instructions'])<br><span class="muted">{{ $settings['instructions'] }}</span>@endif
        </div>
    @endif

    <p class="muted" style="margin-top: 18pt">დოკუმენტი შექმნილია {{ $generatedAt }}.</p>
</body>
</html>
