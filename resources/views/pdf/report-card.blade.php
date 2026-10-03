<!DOCTYPE html>
<html lang="ka">
<head>
    <meta charset="utf-8">
    <style>
        @font-face { font-family: 'NotoGeorgian'; font-weight: normal; src: url('file://{{ str_replace('\\', '/', $fontRegular) }}') format('truetype'); }
        @font-face { font-family: 'NotoGeorgian'; font-weight: bold; src: url('file://{{ str_replace('\\', '/', $fontBold) }}') format('truetype'); }
        body { font-family: 'NotoGeorgian', sans-serif; font-size: 11pt; color: #132B45; }
        h1 { font-size: 18pt; margin: 0 0 4pt; }
        .muted { color: #555; font-size: 9pt; }
        table { width: 100%; border-collapse: collapse; margin-top: 12pt; }
        th, td { border: 1px solid #c9ced6; padding: 5pt 6pt; text-align: left; vertical-align: top; }
        th { background: #f1f3f6; font-weight: bold; }
        .comment { margin-top: 10pt; }
        .footer { margin-top: 18pt; font-size: 8.5pt; color: #555; }
    </style>
</head>
<body>
    <p class="muted">{{ $document['schoolName'] ?? '' }}</p>
    <h1>ტაბელი — {{ $document['studentName'] ?? '' }}</h1>
    <p class="muted">
        {{ $document['className'] ?? '' }}
        @if (! empty($document['academicYearName'])) · {{ $document['academicYearName'] }} @endif
        · {{ $document['periodName'] ?? '' }} ({{ $document['periodStartsOn'] ?? '' }} – {{ $document['periodEndsOn'] ?? '' }})
    </p>

    <table>
        <thead>
            <tr><th>საგანი</th><th>საბოლოო შეფასება</th><th>მასწავლებლის კომენტარი</th></tr>
        </thead>
        <tbody>
            @forelse ($document['subjects'] ?? [] as $line)
                <tr>
                    <td>{{ $line['subject'] ?? '' }}</td>
                    <td>{{ $line['finalMark'] ?? '—' }}</td>
                    <td>{{ $line['teacherComment'] ?? '' }}</td>
                </tr>
            @empty
                <tr><td colspan="3">საგნები არ არის.</td></tr>
            @endforelse
        </tbody>
    </table>

    @if (! empty($document['homeroomComment']))
        <p class="comment"><strong>დამრიგებელი:</strong> {{ $document['homeroomComment'] }}</p>
    @endif
    @if (! empty($document['directorComment']))
        <p class="comment"><strong>დირექტორი:</strong> {{ $document['directorComment'] }}</p>
    @endif

    <p class="footer">
        ვერსია {{ $document['revision'] ?? 1 }} · გამოქვეყნდა {{ isset($document['publishedAt']) ? \Illuminate\Support\Carbon::parse($document['publishedAt'])->timezone($timezone)->format('d.m.Y H:i') : '' }}<br>
        ნამდვილობის შემოწმება: {{ $verificationUrl }} · კოდი {{ $document['verificationCode'] ?? '' }}
    </p>
</body>
</html>
