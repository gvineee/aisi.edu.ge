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
        th, td { border: 1px solid #c9ced6; padding: 5pt 6pt; text-align: left; }
        th { background: #f1f3f6; font-weight: bold; }
        .num { text-align: right; }
        .void { color: #a11; font-weight: bold; font-size: 13pt; }
    </style>
</head>
<body>
    <p class="muted">{{ $schoolName }}</p>
    <h1>ქვითარი № {{ $receipt['number'] }}</h1>
    @if ($receipt['reversed'])
        <p class="void">გატარება გაუქმებულია</p>
    @endif
    <p>
        მოსწავლე: <strong>{{ $receipt['studentName'] }}</strong><br>
        მიღების თარიღი: {{ $receipt['receivedOn'] }}<br>
        მეთოდი: {{ $receipt['method'] }}@if ($receipt['reference']) · საბუთი № {{ $receipt['reference'] }}@endif<br>
        თანხა: <strong>{{ $receipt['amount'] }}</strong>
    </p>

    @if (count($receipt['allocations']) > 0)
        <table>
            <thead><tr><th>დაფარული ინვოისი</th><th class="num">თანხა</th></tr></thead>
            <tbody>
                @foreach ($receipt['allocations'] as $line)
                    <tr><td>№ {{ $line['number'] }}</td><td class="num">{{ $line['amount'] }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif
    @if ($receipt['credit'] !== null)
        <p>ანგარიშზე კრედიტად დარჩა: {{ $receipt['credit'] }}</p>
    @endif

    <p class="muted" style="margin-top: 18pt">დოკუმენტი შექმნილია {{ $generatedAt }}.</p>
</body>
</html>
