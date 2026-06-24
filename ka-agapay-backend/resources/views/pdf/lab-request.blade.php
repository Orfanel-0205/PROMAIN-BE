<!doctype html>
<html>
<head>
    <meta charset="utf-8">
    <title>Laboratory Request</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            color: #111827;
            font-size: 12px;
            line-height: 1.35;
        }
        .header {
            text-align: center;
            margin-bottom: 18px;
        }
        .header div {
            font-weight: 700;
        }
        .title {
            text-align: center;
            font-size: 20px;
            font-weight: 800;
            letter-spacing: 1px;
            margin: 18px 0;
        }
        .row {
            display: table;
            width: 100%;
            margin-bottom: 8px;
        }
        .cell {
            display: table-cell;
            padding-right: 12px;
        }
        .label {
            font-weight: 700;
        }
        .line {
            border-bottom: 1px solid #111827;
            min-height: 18px;
            padding: 0 4px 2px;
        }
        .section {
            border: 1px solid #111827;
            padding: 10px 12px;
            margin-top: 10px;
        }
        .section-title {
            font-weight: 800;
            margin-bottom: 8px;
            text-transform: uppercase;
        }
        .grid {
            display: table;
            width: 100%;
        }
        .col {
            display: table-cell;
            width: 33%;
            vertical-align: top;
            padding-right: 12px;
        }
        .check {
            margin-bottom: 6px;
        }
        .box {
            display: inline-block;
            width: 12px;
            height: 12px;
            border: 1px solid #111827;
            text-align: center;
            line-height: 12px;
            font-size: 10px;
            margin-right: 6px;
        }
        .notes {
            min-height: 46px;
            border: 1px solid #111827;
            padding: 6px;
            margin-top: 4px;
        }
        .footer {
            margin-top: 34px;
            display: table;
            width: 100%;
        }
        .footer-cell {
            display: table-cell;
            width: 50%;
            vertical-align: bottom;
            padding-right: 18px;
        }
        .signature-line {
            border-bottom: 1px solid #111827;
            min-height: 22px;
            text-align: center;
            padding-top: 12px;
        }
        .muted {
            color: #4B5563;
            font-size: 11px;
        }
    </style>
</head>
<body>
    @php
        $lab = $labTests['laboratory'] ?? [];
        $xray = $labTests['xray'] ?? [];
        $ultrasound = $labTests['ultrasound'] ?? [];
        $others = $labTests['others'] ?? [];
        $checked = fn ($option, $items) => in_array($option, $items ?? [], true) ? 'X' : '';
    @endphp

    <div class="header">
        <div>Municipal Health Office</div>
        <div>Rural Health Unit</div>
        <div>Malasiqui, Pangasinan</div>
    </div>

    <div class="row">
        <div class="cell" style="width: 36%;">
            <div class="label">Date</div>
            <div class="line">{{ $date }}</div>
        </div>
        <div class="cell" style="width: 64%;">
            <div class="label">Patient Name</div>
            <div class="line">{{ $patientName }}</div>
        </div>
    </div>

    <div class="row">
        <div class="cell" style="width: 30%;">
            <div class="label">Age / Sex</div>
            <div class="line">{{ $ageSex ?: ' ' }}</div>
        </div>
        <div class="cell" style="width: 70%;">
            <div class="label">Diagnosis / Clinical Impression</div>
            <div class="line">{{ $clinicalImpression ?: ' ' }}</div>
        </div>
    </div>

    <div class="row">
        <div class="cell" style="width: 36%;">
            <div class="label">Consultation ID</div>
            <div class="line">{{ $consultationId ?: ' ' }}</div>
        </div>
        <div class="cell" style="width: 64%;">
            <div class="label">Priority</div>
            <div class="line">{{ ucfirst($priority ?: 'routine') }}</div>
        </div>
    </div>

    <div class="title">LABORATORY REQUEST</div>

    <div class="section">
        <div class="grid">
            <div class="col">
                <div class="section-title">Laboratory</div>
                @foreach ($laboratoryOptions as $option)
                    <div class="check"><span class="box">{{ $checked($option, $lab) }}</span>{{ $option }}</div>
                @endforeach
                <div class="check"><span class="box">{{ !empty($others['laboratory']) ? 'X' : '' }}</span>Others: {{ $others['laboratory'] ?? '' }}</div>
            </div>

            <div class="col">
                <div class="section-title">X-Ray</div>
                @foreach ($xrayOptions as $option)
                    <div class="check"><span class="box">{{ $checked($option, $xray) }}</span>{{ $option }}</div>
                @endforeach
                <div class="check"><span class="box">{{ !empty($others['xray']) ? 'X' : '' }}</span>Others: {{ $others['xray'] ?? '' }}</div>
            </div>

            <div class="col">
                <div class="section-title">Ultrasound</div>
                @foreach ($ultrasoundOptions as $option)
                    <div class="check"><span class="box">{{ $checked($option, $ultrasound) }}</span>{{ $option }}</div>
                @endforeach
                <div class="check"><span class="box">{{ !empty($others['ultrasound']) ? 'X' : '' }}</span>Others: {{ $others['ultrasound'] ?? '' }}</div>
            </div>
        </div>
    </div>

    <div class="section">
        <div class="label">Reason / Indication</div>
        <div class="notes">{{ $reason ?: ' ' }}</div>

        <div class="label" style="margin-top: 10px;">Request Notes</div>
        <div class="notes">{{ $notes ?: ' ' }}</div>
    </div>

    <div class="footer">
        <div class="footer-cell">
            <div class="signature-line">{{ $requestedBy ?: ' ' }}</div>
            <div class="muted" style="text-align: center;">Requested by</div>
        </div>
        <div class="footer-cell">
            <div class="signature-line">{{ $licenseNumber ?: 'License #: ______' }}</div>
            <div class="muted" style="text-align: center;">License number</div>
        </div>
    </div>
</body>
</html>
