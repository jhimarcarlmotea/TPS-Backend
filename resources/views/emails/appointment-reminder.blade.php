<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Appointment reminder</title>
</head>
<body style="margin:0;padding:0;background:#f2f5f3;font-family:Arial,Helvetica,sans-serif;color:#24342d;">
  <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f2f5f3;padding:32px 12px;">
    <tr>
      <td align="center">
        <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="max-width:600px;background:#ffffff;border-radius:18px;overflow:hidden;">
          <tr>
            <td style="background:#174d3d;padding:28px 36px;color:#ffffff;">
              <div style="font-size:13px;font-weight:bold;letter-spacing:3px;">THREEFOLD</div>
              <div style="margin-top:24px;font-size:12px;font-weight:bold;letter-spacing:1.5px;color:#c9dfd4;">APPOINTMENT REMINDER</div>
              <h1 style="margin:8px 0 0;font-size:27px;line-height:1.25;font-weight:600;">Your session is coming up</h1>
            </td>
          </tr>
          <tr>
            <td style="padding:32px 36px 12px;">
              <p style="margin:0 0 12px;font-size:16px;line-height:1.6;">Hi {{ $firstName }},</p>
              <p style="margin:0;color:#58675f;font-size:15px;line-height:1.7;">
                @if ($isOneHourReminder)
                  Your appointment is coming up in about one hour. Please get ready for your session.
                @else
                  This is a friendly reminder that your appointment is scheduled within the next 24 hours.
                @endif
              </p>
            </td>
          </tr>
          <tr>
            <td style="padding:20px 36px 28px;">
              <table role="presentation" width="100%" cellspacing="0" cellpadding="0" style="background:#f5f8f6;border:1px solid #e2ebe5;border-radius:14px;">
                <tr>
                  <td style="padding:22px 24px 8px;">
                    <div style="font-size:11px;font-weight:bold;letter-spacing:1.2px;color:#718078;">DATE</div>
                    <div style="padding-top:6px;font-size:17px;font-weight:bold;color:#174d3d;">{{ $appointmentDate }}</div>
                  </td>
                </tr>
                <tr>
                  <td style="padding:12px 24px 8px;">
                    <div style="font-size:11px;font-weight:bold;letter-spacing:1.2px;color:#718078;">TIME (PHILIPPINE TIME)</div>
                    <div style="padding-top:6px;font-size:17px;font-weight:bold;color:#174d3d;">{{ $startTime }} – {{ $endTime }}</div>
                  </td>
                </tr>
                <tr>
                  <td style="padding:12px 24px 8px;">
                    <div style="font-size:11px;font-weight:bold;letter-spacing:1.2px;color:#718078;">SERVICE</div>
                    <div style="padding-top:6px;font-size:15px;color:#24342d;">{{ $serviceName }}</div>
                  </td>
                </tr>
                @if ($psychologistName)
                  <tr>
                    <td style="padding:12px 24px 8px;">
                      <div style="font-size:11px;font-weight:bold;letter-spacing:1.2px;color:#718078;">PSYCHOLOGIST</div>
                      <div style="padding-top:6px;font-size:15px;color:#24342d;">{{ $psychologistName }}</div>
                    </td>
                  </tr>
                @endif
                <tr>
                  <td style="padding:12px 24px 22px;">
                    <div style="font-size:11px;font-weight:bold;letter-spacing:1.2px;color:#718078;">BOOKING REFERENCE</div>
                    <div style="padding-top:6px;font-size:14px;font-weight:bold;letter-spacing:1px;color:#24342d;">{{ $reference }}</div>
                  </td>
                </tr>
              </table>
            </td>
          </tr>
          <tr>
            <td style="padding:0 36px 28px;">
              <p style="margin:0;color:#58675f;font-size:14px;line-height:1.7;">
                If your plans change, please manage your appointment through your Threefold account.
              </p>
            </td>
          </tr>
          <tr>
            <td style="border-top:1px solid #e8eeea;padding:20px 36px 24px;color:#7a8780;font-size:12px;line-height:1.6;">
              This is an automated reminder from Threefold. Please do not reply to this email.
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
