<!doctype html>
<html>
<body style="font-family:Arial,sans-serif;background:#f4f4f4;padding:32px;">
  <div style="max-width:520px;margin:0 auto;background:#fff;border-radius:16px;padding:32px;">
    <h1 style="font-size:20px;color:#202020;margin:0 0 8px;">Confirm your new email</h1>
    <p style="color:#555;font-size:14px;margin:0 0 20px;">
      Hi {{ $firstName }}, use the code below to finish changing your email address.
      It expires in 15 minutes.
    </p>
    <div style="font-size:32px;font-weight:bold;letter-spacing:8px;color:#1f4d3f;background:#f2f7f4;border-radius:12px;padding:16px;text-align:center;">
      {{ $code }}
    </div>
    <p style="color:#888;font-size:12px;margin:20px 0 0;">
      If you didn't request this, you can ignore the email — nothing will change.
    </p>
  </div>
</body>
</html>