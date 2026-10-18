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
    </style>
</head>
<body>
    <p class="muted">{{ $schoolName }}@if ($settings && $settings['recipientCode']) · ს/კ {{ $settings['recipientCode'] }}@endif</p>
    <h1>საკრედიტო ნოტა № {{ $creditNote['number'] }}</h1>
    <p>
        თარიღი: {{ $creditNote['issuedOn'] }}<br>
        მოსწავლე: <strong>{{ $creditNote['studentName'] }}</strong>@if ($creditNote['className']) · {{ $creditNote['className'] }}@endif<br>
        კორექტირებული ინვოისი: № {{ $creditNote['invoiceNumber'] }} ({{ $creditNote['invoiceIssuedOn'] }})
    </p>

    <table>
        <thead><tr><th>საფუძველი</th><th class="num">შემცირების თანხა</th></tr></thead>
        <tbody>
            <tr><td>{{ $creditNote['reason'] }}</td><td class="num">{{ $creditNote['amount'] }}</td></tr>
        </tbody>
    </table>

    @if ($creditNote['released'])
        <p>ამ ინვოისზე უკვე გადახდილი {{ $creditNote['released'] }} გადავიდა მოსწავლის ანგარიშის კრედიტზე.</p>
    @endif

    <p class="muted" style="margin-top: 18pt">დოკუმენტი შექმნილია {{ $generatedAt }}.</p>
</body>
</html>
