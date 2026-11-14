<!DOCTYPE html>
<html lang="en">
<head><meta charset="utf-8"><title>{{ $subjectLine }}</title></head>
<body style="margin:0;padding:0;background:#f4f6fa;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f4f6fa;padding:24px 0;">
    <tr><td align="center">
      <table role="presentation" width="560" cellspacing="0" cellpadding="0" style="max-width:560px;width:100%;background:#ffffff;border-radius:8px;overflow:hidden;">
        <tr><td style="background:#0f2a5f;color:#ffffff;padding:18px 24px;font-size:16px;font-weight:bold;">Smart System Investment &mdash; Registration Team</td></tr>
        <tr><td style="padding:24px;font-size:15px;line-height:1.55;">
          <p style="margin:0 0 14px;">Hello{{ $applicantName ? ' ' . $applicantName : '' }},</p>
          {{-- Escaped, never raw: staff-typed text is rendered as text only. --}}
          <div>{!! nl2br(e($messageBody)) !!}</div>
        </td></tr>
        <tr><td style="padding:16px 24px;font-size:12px;color:#6b7280;border-top:1px solid #e5e7eb;">
          You are receiving this message because you started a registration with Smart System Investment.
          @if($isFollowUp)
            If you do not wish to receive further follow-up messages about this application, simply reply to let the Registration Team know.
          @endif
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
