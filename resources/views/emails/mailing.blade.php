<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $subjectLine }}</title>
</head>
<body style="margin:0; padding:0; background:#F1F5F9; font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Arial, sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#F1F5F9; padding:32px 0;">
    <tr><td align="center">
      <table role="presentation" width="600" cellpadding="0" cellspacing="0" style="max-width:100%; background:#FFFFFF; border-radius:16px; overflow:hidden;">
        <tr><td style="background:linear-gradient(135deg,#1A3A8F,#2552C4); padding:28px 32px; color:#fff; font-size:18px; font-weight:700;">
          ◆ Smart<span style="font-weight:400;">System</span> Investment
          @if($environment === 'investor')
            <div style="color:#BFD0FF; font-size:12px; letter-spacing:.08em; text-transform:uppercase; padding-top:6px;">Investor Communication</div>
          @endif
        </td></tr>
        <tr><td style="padding:36px 32px; color:#1E293B; font-size:15px; line-height:1.7;">{!! $bodyHtml !!}</td></tr>
        <tr><td style="padding:24px 32px; background:#F8FAFC; border-top:1px solid #E2E8F0;">
          @if($environment === 'investor')
            <p style="margin:0; font-size:12px; color:#94A3B8; line-height:1.6;">This message was sent to a verified Smart System Investment investor. We will never ask for your password, PIN or withdrawal details by email.</p>
          @else
            <p style="margin:0; font-size:12px; color:#94A3B8; line-height:1.6;">You are receiving this message from Smart System Investment because you contacted us or subscribed to our updates.</p>
          @endif
          @if(!empty($unsubscribeUrl))
            <p style="margin:8px 0 0; font-size:12px; color:#94A3B8;">You can <a href="{{ $unsubscribeUrl }}" style="color:#2552C4;">unsubscribe from these optional emails</a> at any time.@if($environment === 'investor') Essential account and security notices are not affected.@endif</p>
          @endif
          <p style="margin:8px 0 0; font-size:12px; color:#CBD5E1;">&copy; {{ date('Y') }} Smart System Investment. All rights reserved.</p>
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
