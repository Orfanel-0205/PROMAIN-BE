<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Ka-Agapay Medical Prescription</title>

    <style>
        @page {
            margin: 24px 28px;
        }

        body {
            font-family: DejaVu Sans, Arial, sans-serif;
            color: #0f172a;
            font-size: 12px;
            line-height: 1.45;
            background: #ffffff;
        }

        .sheet {
            border: 2px solid #0f766e;
            padding: 18px 22px 20px;
            min-height: 94%;
            position: relative;
        }

        .header {
            border-bottom: 3px solid #0f766e;
            padding-bottom: 12px;
            margin-bottom: 14px;
        }

        .brand-left {
            width: 62%;
            display: inline-block;
            vertical-align: top;
        }

        .brand-right {
            width: 36%;
            display: inline-block;
            text-align: right;
            vertical-align: top;
            font-size: 10px;
            color: #475569;
        }

        .logo-circle {
            display: inline-block;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: #0f766e;
            color: white;
            text-align: center;
            line-height: 44px;
            font-size: 22px;
            font-weight: bold;
            margin-right: 10px;
            vertical-align: middle;
        }

        .brand-text {
            display: inline-block;
            vertical-align: middle;
        }

        .brand-title {
            font-size: 20px;
            font-weight: bold;
            color: #064e3b;
            margin: 0;
        }

        .brand-subtitle {
            margin: 1px 0 0;
            color: #334155;
            font-size: 11px;
        }

        .rx-title {
            text-align: center;
            color: #064e3b;
            margin: 14px 0 4px;
            font-size: 19px;
            font-weight: bold;
            letter-spacing: 1.2px;
            text-transform: uppercase;
        }

        .rx-subtitle {
            text-align: center;
            font-size: 10px;
            color: #64748b;
            margin-bottom: 10px;
        }

        .meta-grid {
            width: 100%;
            margin-bottom: 12px;
        }

        .meta-box {
            display: inline-block;
            width: 31.5%;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 8px;
            margin-right: 5px;
            vertical-align: top;
            min-height: 43px;
        }

        .label {
            display: block;
            font-size: 9px;
            color: #64748b;
            text-transform: uppercase;
            font-weight: bold;
            letter-spacing: .5px;
            margin-bottom: 3px;
        }

        .value {
            display: block;
            color: #0f172a;
            font-weight: bold;
            font-size: 12px;
        }

        .patient-section {
            border: 1px solid #99f6e4;
            background: #f0fdfa;
            padding: 10px 12px;
            margin-bottom: 14px;
        }

        .patient-col {
            display: inline-block;
            width: 48.5%;
            vertical-align: top;
        }

        .rx-row {
            margin-top: 8px;
            margin-bottom: 8px;
        }

        .rx-symbol {
            display: inline-block;
            width: 58px;
            vertical-align: top;
            font-size: 48px;
            font-weight: bold;
            color: #0f766e;
            line-height: 1;
        }

        .rx-instruction {
            display: inline-block;
            width: 86%;
            vertical-align: top;
            padding-top: 10px;
            color: #334155;
            font-size: 11px;
        }

        .med-table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 8px;
            margin-bottom: 16px;
        }

        .med-table th {
            background: #0f766e;
            color: white;
            padding: 8px 7px;
            font-size: 10px;
            text-align: left;
            text-transform: uppercase;
        }

        .med-table td {
            border: 1px solid #cbd5e1;
            padding: 8px 7px;
            vertical-align: top;
            font-size: 11px;
        }

        .med-name {
            font-weight: bold;
            color: #0f172a;
        }

        .instructions {
            border: 1px solid #cbd5e1;
            padding: 10px 12px;
            min-height: 55px;
            margin-bottom: 16px;
        }

        .notice {
            background: #f8fafc;
            border-left: 4px solid #0f766e;
            padding: 8px 10px;
            font-size: 10px;
            color: #334155;
            margin-bottom: 18px;
        }

        .signature-row {
            margin-top: 22px;
            width: 100%;
        }

        .signature-box {
            display: inline-block;
            width: 45%;
            vertical-align: top;
            text-align: center;
        }

        .signature-space {
            height: 46px;
            border-bottom: 1px solid #0f172a;
            margin-bottom: 5px;
        }

        .signature-name {
            font-weight: bold;
            color: #0f172a;
        }

        .signature-title {
            font-size: 10px;
            color: #64748b;
        }

        .footer {
            position: absolute;
            left: 22px;
            right: 22px;
            bottom: 16px;
            border-top: 1px solid #cbd5e1;
            padding-top: 8px;
            font-size: 9px;
            color: #64748b;
        }
    </style>
</head>

<body>
    <div class="sheet">
        <div class="header">
            <div class="brand-left">
                <span class="logo-circle">K</span>
                <div class="brand-text">
                    <p class="brand-title">Ka-Agapay {{ $rhuName ?? 'RHU Malasiqui' }}</p>
                    <p class="brand-subtitle">Municipal Health Services • {{ $municipality ?? 'Malasiqui, Pangasinan' }}</p>
                </div>
            </div>

            <div class="brand-right">
                <strong>Official E-Prescription</strong><br>
                Rural Health Unit<br>
                {{ $municipality ?? 'Malasiqui, Pangasinan' }}
            </div>

            <div class="rx-title">Medical Prescription</div>
            <div class="rx-subtitle">Generated from Ka-Agapay RHU Admin Portal</div>
        </div>

        <div class="meta-grid">
            <div class="meta-box">
                <span class="label">Prescription No.</span>
                <span class="value">{{ $prescriptionNo }}</span>
            </div>

            <div class="meta-box">
                <span class="label">Date Issued</span>
                <span class="value">{{ $date }}</span>
            </div>

            <div class="meta-box">
                <span class="label">Valid Until</span>
                <span class="value">{{ $validUntil }}</span>
            </div>
        </div>

        <div class="patient-section">
            <div class="patient-col">
                <span class="label">Patient Name</span>
                <span class="value">{{ $patientName }}</span>
            </div>

            <div class="patient-col">
                <span class="label">Diagnosis / Assessment</span>
                <span class="value">
                    {{ $diagnosis }}
                    @if (!empty($diagnosisCode))
                        <br><small>Code: {{ $diagnosisCode }}</small>
                    @endif
                </span>
            </div>
        </div>

        <div class="rx-row">
            <div class="rx-symbol">Rx</div>
            <div class="rx-instruction">
                Please dispense the following medicine/s according to the dosage, route, frequency, and duration below.
            </div>
        </div>

        <table class="med-table">
            <thead>
                <tr>
                    <th style="width: 25%;">Medicine</th>
                    <th style="width: 12%;">Dosage</th>
                    <th style="width: 10%;">Qty</th>
                    <th style="width: 15%;">Frequency</th>
                    <th style="width: 12%;">Duration</th>
                    <th style="width: 10%;">Route</th>
                    <th style="width: 16%;">Instructions</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($medicines as $medicine)
                    <tr>
                        <td class="med-name">{{ $medicine['name'] }}</td>
                        <td>{{ $medicine['dosage'] ?: '—' }}</td>
                        <td>{{ $medicine['quantity'] ?: '—' }}</td>
                        <td>{{ $medicine['frequency'] ?: '—' }}</td>
                        <td>{{ $medicine['duration'] ?: '—' }}</td>
                        <td>{{ $medicine['route'] ?: '—' }}</td>
                        <td>{{ $medicine['instructions'] ?: 'Take as directed by RHU staff.' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>

        <div class="instructions">
            <span class="label">Additional Instructions</span>
            {{ $additionalInstructions ?? 'Follow the prescribed dosage. Return to RHU if symptoms persist or worsen.' }}

            @if (!empty($dispensingNotes))
                <br><br>
                <span class="label">Dispensing Notes</span>
                {{ $dispensingNotes }}
            @endif
        </div>

        <div class="notice">
            This e-prescription was generated by Ka-Agapay. Please verify all details with authorized RHU staff before releasing or dispensing medicines.
        </div>

        <div class="signature-row">
            <div class="signature-box">
                <div class="signature-space"></div>
                <div class="signature-name">{{ $doctorName }}</div>
                <div class="signature-title">Authorized RHU Staff / Physician</div>
            </div>

            <div class="signature-box" style="float: right;">
                <div class="signature-space"></div>
                <div class="signature-name">Pharmacy / Releasing Staff</div>
                <div class="signature-title">Signature Over Printed Name</div>
            </div>
        </div>

        <div class="footer">
            Generated by Ka-Agapay RHU Admin Portal • This document is valid only with verification from authorized RHU personnel.
        </div>
    </div>
</body>
</html>