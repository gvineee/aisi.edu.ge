<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="utf-8">
    <style>
        @font-face { font-family: 'NotoGeorgian'; font-weight: normal; src: url('file://{{ str_replace('\\', '/', $fontRegular) }}') format('truetype'); }
        @font-face { font-family: 'NotoGeorgian'; font-weight: bold; src: url('file://{{ str_replace('\\', '/', $fontBold) }}') format('truetype'); }
        @page { margin: 18mm; }
        body { font-family: 'NotoGeorgian', sans-serif; font-size: 12pt; color: #132B45; }
        .frame { padding: 14mm 14mm 10mm; }
        .framed { border: 3pt double #132B45; }
        .school { text-align: center; font-size: 11pt; letter-spacing: 1pt; color: #555; }
        h1 { text-align: center; font-size: {{ $document['kind'] === 'letter' ? '16pt' : '26pt' }}; margin: 10pt 0 18pt; }
        .body { line-height: 1.6; {{ $document['kind'] === 'letter' ? '' : 'text-align: center;' }} }
        .meta { margin-top: 28pt; width: 100%; }
        .meta td { vertical-align: bottom; font-size: 10pt; }
        .sign { text-align: right; }
        .line { display: inline-block; border-top: 1px solid #132B45; padding-top: 3pt; min-width: 60mm; text-align: center; }
        .footer { margin-top: 18pt; font-size: 8pt; color: #555; }
        .revoked { color: #b42318; font-weight: bold; text-align: center; font-size: 14pt; margin-bottom: 8pt; }
    </style>
</head>
<body>
    <div class="frame {{ $document['kind'] === 'letter' ? '' : 'framed' }}">
        @if ($revoked)
            <p class="revoked">გაუქმებულია — ძალაში არ არის</p>
        @endif
        <p class="school">{{ $document['schoolName'] }}</p>
        <h1>{{ $document['title'] }}</h1>
        <div class="body">{!! nl2br(e($document['body'])) !!}</div>

        <table class="meta">
            <tr>
                <td>№ {{ $document['number'] }}<br>{{ \Illuminate\Support\Carbon::parse($document['issuedOn'])->format('d.m.Y') }}</td>
                <td class="sign">
                    @if (! empty($document['signatory']))
                        <span class="line">{{ $document['signatory'] }}</span>
                    @endif
                </td>
            </tr>
        </table>

        <p class="footer">ნამდვილობის შემოწმება: {{ $verificationUrl }} · კოდი {{ $verificationCode }}</p>
    </div>
</body>
</html>
