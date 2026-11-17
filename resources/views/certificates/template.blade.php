<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Certificate of Completion</title>
    <style>
        @page {
            margin: 0;
        }

        body {
            font-family: 'DejaVu Sans', sans-serif;
            margin: 0;
            padding: 0;
            color: #1f2937;
        }

        .frame {
            position: relative;
            margin: 18px;
            padding: 34px 44px;
            border: 10px solid #0f766e;
            height: 470px;
        }

        .inner {
            border: 2px solid #d1d5db;
            padding: 34px 40px;
            height: 382px;
        }

        .brand {
            font-size: 13px;
            letter-spacing: 4px;
            text-transform: uppercase;
            color: #0f766e;
            text-align: center;
        }

        .title {
            font-size: 38px;
            font-weight: bold;
            letter-spacing: 3px;
            text-transform: uppercase;
            text-align: center;
            margin: 14px 0 6px;
            color: #111827;
        }

        .subtitle {
            font-size: 13px;
            text-align: center;
            color: #6b7280;
            margin-bottom: 26px;
        }

        .presented {
            text-align: center;
            font-size: 13px;
            color: #6b7280;
        }

        .student {
            text-align: center;
            font-size: 30px;
            font-weight: bold;
            margin: 8px 0;
            color: #0f766e;
        }

        .statement {
            text-align: center;
            font-size: 13px;
            color: #374151;
            margin: 4px 0;
        }

        .course {
            text-align: center;
            font-size: 21px;
            font-weight: bold;
            margin: 6px 0 18px;
        }

        .meta {
            width: 100%;
            margin-top: 26px;
        }

        .meta td {
            width: 33.33%;
            text-align: center;
            vertical-align: bottom;
            font-size: 11px;
            color: #6b7280;
        }

        .meta .line {
            border-top: 1px solid #9ca3af;
            width: 78%;
            margin: 0 auto 6px;
        }

        .meta .value {
            font-size: 13px;
            font-weight: bold;
            color: #111827;
            margin-bottom: 4px;
        }

        .number {
            position: absolute;
            bottom: 14px;
            right: 24px;
            font-size: 9px;
            color: #9ca3af;
            letter-spacing: 1px;
        }
    </style>
</head>
<body>
<div class="frame">
    <div class="inner">
        <div class="brand">{{ $provider }}</div>
        <div class="title">Certificate</div>
        <div class="subtitle">of completion</div>

        <div class="presented">This certificate is proudly presented to</div>
        <div class="student">{{ $studentName }}</div>
        <div class="statement">for successfully completing all lessons in</div>
        <div class="course">{{ $courseTitle }}</div>

        <table class="meta">
            <tr>
                <td>
                    <div class="value">{{ $issuedAt }}</div>
                    <div class="line"></div>
                    Date of issue
                </td>
                <td>
                    <div class="value">{{ $certificate->certificate_number }}</div>
                    <div class="line"></div>
                    Certificate number
                </td>
                <td>
                    <div class="value">{{ $signature }}</div>
                    <div class="line"></div>
                    Authorized signature
                </td>
            </tr>
        </table>
    </div>
    <div class="number">VERIFY AT /api/certificates/verify/{{ $certificate->certificate_number }}</div>
</div>
</body>
</html>
