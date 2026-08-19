<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{{ $code }} – your {{ config('app.name') }} login code</title>
</head>
{{--
    Plain inline styles throughout, no <style> block: this is read inside
    Gmail/Outlook/etc., not a browser, and inbox CSS support is inconsistent
    enough that inline is the only reliable option. White background rather
    than the product's own dark theme, for the same reason the reference
    Spotify email is light — a code has to stay legible regardless of which
    email client's own light/dark rendering wraps it.
--}}
<body style="margin:0; padding:0; background-color:#f5f5f5; font-family:Helvetica,Arial,sans-serif;">
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f5f5f5; padding:40px 0;">
    <tr>
      <td align="center">
        <table role="presentation" width="480" cellpadding="0" cellspacing="0" style="background-color:#ffffff; border-radius:8px; padding:40px;">
          <tr>
            <td style="font-size:22px; font-weight:800; color:#1ed760; letter-spacing:-0.02em; padding-bottom:32px;">
              {{ config('app.name') }}
            </td>
          </tr>
          <tr>
            <td style="font-size:15px; color:#191414; line-height:1.6; padding-bottom:16px;">
              Hi,
            </td>
          </tr>
          <tr>
            <td style="font-size:15px; color:#191414; line-height:1.6; padding-bottom:24px;">
              Enter this code to continue logging in without a password:
            </td>
          </tr>
          <tr>
            <td style="font-size:32px; font-weight:800; color:#191414; letter-spacing:0.05em; padding-bottom:24px;">
              {{ $code }}
            </td>
          </tr>
          <tr>
            <td style="font-size:14px; color:#535353; line-height:1.6; padding-bottom:16px;">
              This code is valid for {{ $ttlMinutes }} minutes and can only be used once. By entering this code, you will also confirm the email address associated with your account.
            </td>
          </tr>
          <tr>
            <td style="font-size:14px; color:#535353; line-height:1.6; padding-bottom:24px;">
              If you didn't attempt to log in, you can safely ignore this email.
            </td>
          </tr>
          <tr>
            <td style="font-size:14px; color:#191414; line-height:1.6;">
              Best regards,<br>
              {{ config('app.name') }}
            </td>
          </tr>
        </table>
      </td>
    </tr>
  </table>
</body>
</html>
