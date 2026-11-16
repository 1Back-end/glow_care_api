<!DOCTYPE html>
<html>
<head>
    <meta http-equiv="Content-Type" content="text/html; charset=utf-8"/>
    <title>Carte de fidélité</title>

    <style>
        {!! $bootstrap !!}
    </style>

    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }

        @page { size: A6 landscape; margin: 0; }

        html, body {
            width: 100%;
            height: 100%;
            background-color: #2f1f35;
            font-family: "Helvetica", "Arial", sans-serif;
            color: #ffffff;
            -webkit-print-color-adjust: exact;
        }

        .card {
            width: 100%;
            height: 100%;
            padding: 22px 24px;
            background-color: #2f1f35;
        }

        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: middle; }

        /* En-tête */
        .brand-name {
            font-size: 20px;
            font-weight: bold;
            color: #f9c6d6;
        }
        .card-type {
            font-size: 10px;
            letter-spacing: 0.5px;
            color: #c9bfcc;
            text-transform: uppercase;
            padding-top: 6px;
        }
        .crown { text-align: right; }
        .crown svg, .crown img { width: 26px; height: 22px; }
        .client-label {
            font-size: 17px;
            font-weight: bold;
            color: #ffffff;
            text-transform: uppercase;
            padding-top: 18px;
            padding-bottom: 14px;
        }

        .counter {
            font-size: 24px;
            font-weight: bold;
            color: #f5b840;
        }
        .badge-cell { text-align: right; }
        .badge {
            display: inline-block;
            font-size: 10px;
            font-weight: bold;
            color: #4ade80;
            background-color: #1f3a33;
            padding: 4px 10px;
            border-radius: 12px;
        }

        .sub-label {
            font-size: 11px;
            color: #e8dfe9;
            padding-top: 6px;
            padding-bottom: 8px;
        }
        .segments { width: 100%; border-collapse: separate; border-spacing: 4px 0; margin-left: -4px; }
        .segment {
            height: 9px;
            border-radius: 5px;
            background-color: #4d3a56;
        }
        .segment.done { background-color: #f2b6d2; }

        .message {
            font-size: 11px;
            color: #e8dfe9;
            padding-top: 12px;
        }
    </style>
</head>
<body>

@php
    $count = (int) ($client->count_prestations ?? 0);
    $cycle = $count % 20;
    if ($cycle === 0 && $count > 0) {
        $cycle = 20;
    }

    if ($cycle >= 20) {
        $target = 20; $filled = 10;
    } elseif ($cycle >= 10) {
        $target = 20; $filled = $cycle - 10;
    } else {
        $target = 10; $filled = $cycle;
    }

    $maxPrestations = 10;
    $currentCycle = $filled;
    $remaining = $target - $cycle;
    $level = $client->level ?? 'Silver';

    if ($cycle >= 20) {
        $message = "Félicitations ! Votre prestation offerte est disponible !";
    } elseif ($cycle === 10) {
        $message = "Réduction acquise ! Encore 10 prestations pour une prestation offerte.";
    } else {
        $reward = $target === 10 ? 'votre réduction' : 'votre prestation offerte';
        $message = "Encore {$remaining} prestation" . ($remaining > 1 ? 's' : '') . " pour obtenir {$reward} !";
    }
@endphp

<div class="card">

    <!-- En-tête -->
    <table>
        <tr>
            <td>
                <div class="brand-name">{{ $establishment->name }}</div>
            </td>
            <td class="crown">
                @if(!empty($logo))
                    <img src="data:{{ $mimetype }};base64,{{ $logo }}" alt="Logo">
                @else
                    <svg viewBox="0 0 24 20" xmlns="http://www.w3.org/2000/svg">
                        <path d="M2 17h20v2H2zM2 6l5 4 5-7 5 7 5-4-2 9H4z" fill="none" stroke="#f5b840" stroke-width="1.6" stroke-linejoin="round"/>
                    </svg>
                @endif
            </td>
        </tr>
    </table>
    <div class="card-type">Beauty Rewards Card</div>

    <div class="client-label">Client fidèle</div>

    <table>
        <tr>
            <td class="counter">
                {{ str_pad($cycle, 2, '0', STR_PAD_LEFT) }} / {{ $target }}
            </td>
            <td class="badge-cell">
                <span class="badge">{{ $level }}</span>
            </td>
        </tr>
    </table>

    <div class="sub-label">Prestations validées</div>

    <table class="segments">
        <tr>
            @for ($i = 1; $i <= $maxPrestations; $i++)
                <td class="segment {{ $i <= $currentCycle ? 'done' : '' }}"></td>
            @endfor
        </tr>
    </table>

    <div class="message">{{ $message }}</div>

</div>

</body>
</html>
