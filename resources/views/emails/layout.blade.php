<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>@yield('title', 'Meridian Travel')</title>
</head>
<body style="margin: 0; padding: 0; background-color: #f3f6fb; font-family: Arial, Helvetica, sans-serif; color: #1f2937;">
    <table role="presentation" width="100%" cellspacing="0" cellpadding="0" border="0" style="background-color: #f3f6fb; padding: 24px 0;">
        <tr>
            <td align="center">
                <table role="presentation" width="640" cellspacing="0" cellpadding="0" border="0" style="background-color: #ffffff; border-radius: 10px; overflow: hidden; box-shadow: 0 6px 24px rgba(15, 23, 42, 0.08);">
                    <tr>
                        <td style="background: linear-gradient(90deg, #0f172a 0%, #1d4ed8 100%); padding: 28px 32px; text-align: left;">
                            <h1 style="margin: 0; font-size: 24px; color: #ffffff; font-weight: 700;">@yield('heading', 'Meridian Travel')</h1>
                            <p style="margin: 8px 0 0; font-size: 14px; color: #dbeafe;">@yield('subheading', '')</p>
                        </td>
                    </tr>
                    <tr>
                        <td style="padding: 32px;">
                            @yield('content')
                        </td>
                    </tr>
                </table>
            </td>
        </tr>
    </table>
</body>
</html>
