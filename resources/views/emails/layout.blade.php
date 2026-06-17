<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'ASALAW Notification')</title>
</head>
<body style="margin:0;padding:0;background-color:#f4f6fb;font-family:Arial,Helvetica,sans-serif;color:#1f2937;">
    @php
        $storedLogoPath = storage_path('app/public/logo-landscape.png');
        $fallbackLogoUrl = asset('images/aslaw-logo.png');
        $logoSource = $fallbackLogoUrl;

        if (file_exists($storedLogoPath) && isset($message) && is_object($message) && method_exists($message, 'embed')) {
            $logoSource = $message->embed($storedLogoPath);
        }
    @endphp

    <span style="display:none!important;visibility:hidden;opacity:0;color:transparent;height:0;width:0;overflow:hidden;">
        @yield('preheader', 'Important update from ASALAW.')
    </span>

    <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="background-color:#f4f6fb;margin:0;padding:24px 12px;">
        <tr>
            <td align="center">
                <table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="max-width:680px;">
                    <tr>
                        <td style="padding:0 0 16px 0;text-align:center;">
                            <img src="{{ $logoSource }}" alt="ASALAW" style="display:block;margin:0 auto 12px auto;max-width:220px;width:100%;height:auto;">
                            <div style="font-size:12px;letter-spacing:0.14em;text-transform:uppercase;color:#64748b;">Legal Management Platform</div>
                        </td>
                    </tr>
                    <tr>
                        <td style="background-color:#ffffff;border:1px solid #e5e7eb;border-radius:18px;padding:40px 36px;box-shadow:0 10px 30px rgba(15,23,42,0.08);">
                            <div style="font-size:28px;line-height:1.2;font-weight:700;color:#0f172a;margin:0 0 12px 0;">
                                @yield('heading')
                            </div>
                            <div style="font-size:15px;line-height:1.8;color:#475569;">
                                @yield('content')
                            </div>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding:18px 12px 0 12px;text-align:center;font-size:12px;line-height:1.7;color:#64748b;">
                            This is an automated message from ASALAW. Please do not reply directly to this email.<br>
                            © {{ now()->year }} ASALAW. All rights reserved.
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>