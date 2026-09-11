<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $subject }}</title>
</head>
<body style="margin:0; padding:0; background:#F1F5F9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F1F5F9; padding:32px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="background:#FFFFFF; border-radius:16px; overflow:hidden; box-shadow:0 4px 24px rgba(0,0,0,0.06);">

          {{-- HEADER / LOGO --}}
          <tr>
            <td style="background:linear-gradient(135deg,#1A3A8F,#2552C4); padding:28px 32px;">
              <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                <tr>
                  <td style="color:#ffffff; font-size:18px; font-weight:700; letter-spacing:0.02em;">
                    ◆ Smart<span style="font-weight:400;">System</span> Investment
                  </td>
                </tr>
              </table>
            </td>
          </tr>

          {{-- BODY --}}
          <tr>
            <td style="padding:36px 32px; color:#1E293B; font-size:15px; line-height:1.7;">
              {!! $bodyHtml !!}
            </td>
          </tr>

          {{-- FOOTER --}}
          <tr>
            <td style="padding:24px 32px; background:#F8FAFC; border-top:1px solid #E2E8F0;">
              <p style="margin:0; font-size:12px; color:#94A3B8; line-height:1.6;">
                This email was sent by Smart System Investment. If you did not expect this message,
                please contact our support team.
              </p>
              <p style="margin:8px 0 0; font-size:12px; color:#CBD5E1;">
                &copy; {{ date('Y') }} Smart System Investment. All rights reserved.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>