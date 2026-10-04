{{-- Account email (AccountMailService). Inline styles only: mail clients drop <style> blocks. --}}
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>{{ $heading }}</title>
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:Arial,Helvetica,sans-serif;color:#0f172a;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f1f5f9;padding:24px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:480px;background:#ffffff;border-radius:16px;border:1px solid #e2e8f0;">
          <tr>
            <td style="background:#047857;border-radius:16px 16px 0 0;padding:18px 24px;color:#ffffff;font-size:18px;font-weight:bold;">
              Ka-Agapay
            </td>
          </tr>
          <tr>
            <td style="padding:24px;">
              <h1 style="margin:0 0 12px;font-size:20px;">{{ $heading }}</h1>
              <p style="margin:0 0 16px;font-size:15px;line-height:1.5;">Hi {{ $name }},</p>
              <p style="margin:0 0 16px;font-size:15px;line-height:1.5;">{{ $intro }}</p>

              @if (!empty($code))
                <p style="margin:0 0 20px;text-align:center;">
                  <span style="display:inline-block;padding:14px 22px;background:#ecfdf5;border:1px solid #a7f3d0;border-radius:12px;font-size:30px;font-weight:bold;letter-spacing:8px;color:#065f46;">{{ $code }}</span>
                </p>
              @endif

              @foreach ($lines as $line)
                <p style="margin:0 0 12px;font-size:14px;line-height:1.5;color:#334155;">{{ $line }}</p>
              @endforeach
            </td>
          </tr>
          <tr>
            <td style="padding:16px 24px;border-top:1px solid #e2e8f0;font-size:12px;color:#64748b;">
              Sent by the Ka-Agapay system of your Rural Health Unit. Please do not reply to this email.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
