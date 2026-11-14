<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>Verify your email</title></head>
<body style="margin:0;padding:0;background:#f4f5f7;font-family:Arial,Helvetica,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="padding:32px 0;"><tr><td align="center">
    <table width="480" cellpadding="0" cellspacing="0" style="max-width:480px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;">
      <tr><td style="background:#0f172a;padding:24px 32px;"><span style="color:#ffffff;font-size:18px;font-weight:bold;">Smart System Investment</span></td></tr>
      <tr><td style="padding:32px;">
        <p style="font-size:15px;color:#1f2937;margin:0 0 24px;">
          Someone asked to register with this email address. To confirm it is you, enter the code below. It expires in {{ $ttlMinutes }} minutes and works once.
        </p>
        <div style="text-align:center;margin:0 0 24px;">
          <span style="display:inline-block;padding:14px 28px;background:#f1f5f9;border-radius:6px;font-size:28px;letter-spacing:8px;font-weight:bold;color:#0f172a;">{{ $code }}</span>
        </div>
        <p style="font-size:13px;color:#6b7280;margin:0;">
          <strong>If this was not you, do nothing.</strong> No account has been created and none will be without this code.
          Never share this code with anyone, including people who say they work for SSI.
        </p>
      </td></tr>
    </table>
  </td></tr></table>
</body>
</html>
