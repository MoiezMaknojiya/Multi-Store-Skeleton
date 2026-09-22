{{-- The shell every email the app sends is drawn in: one 600px column, a brand bar, a white card and a
     quiet footer. Written the way email has to be written rather than the way the panel is — tables, not
     flexbox (Outlook renders with Word's engine), every rule inline (Gmail strips <style> blocks), and no
     images, so a blocked-images inbox still shows the whole message.

     The colours are the panel's own (blue-600 #2563eb on gray-100 #f3f4f6). The card stays #ffffff rather
     than transparent: the clients that force dark mode turn white into a dark grey that still reads, while
     a transparent card can end up with dark text on a dark background. --}}
@props(['title', 'preheader' => ''])
<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="x-apple-disable-message-reformatting">
    {{-- Says the message is drawn for both schemes, so a client tints it instead of inverting it wholesale. --}}
    <meta name="color-scheme" content="light dark">
    <meta name="supported-color-schemes" content="light dark">
    <title>{{ $title }}</title>
</head>

<body style="margin:0; padding:0; width:100%; background-color:#f3f4f6;">
    {{-- The line the inbox shows beside the subject, then enough blank space to stop it borrowing the
         body's first words. --}}
    <div style="display:none; max-height:0; overflow:hidden; opacity:0; color:transparent; height:0; width:0;">
        {{ $preheader }}&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;&#8199;&#65279;&#847;
    </div>

    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"
           style="background-color:#f3f4f6;">
        <tr>
            <td align="center" style="padding:32px 12px;">
                <table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" align="center"
                       style="width:600px; max-width:600px; margin:0 auto;">

                    {{-- Brand bar --}}
                    <tr>
                        <td align="center" bgcolor="#2563eb"
                            style="background-color:#2563eb; border-radius:12px 12px 0 0; padding:20px 24px;">
                            <span style="font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:18px; font-weight:700; letter-spacing:.3px; color:#ffffff;">
                                {{ config('app.name') }}
                            </span>
                        </td>
                    </tr>

                    {{-- The message itself --}}
                    <tr>
                        <td bgcolor="#ffffff"
                            style="background-color:#ffffff; border:1px solid #e5e7eb; border-top:0; border-radius:0 0 12px 12px; padding:32px 32px 28px 32px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
                            {{ $slot }}
                        </td>
                    </tr>

                    {{-- Footer --}}
                    <tr>
                        <td align="center" style="padding:20px 16px 0 16px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:12px; line-height:18px; color:#6b7280;">
                            {{ $footer ?? '' }}
                            <div style="margin-top:8px;">
                                &copy; {{ now()->year }} {{ config('app.name') }}
                            </div>
                        </td>
                    </tr>

                </table>
            </td>
        </tr>
    </table>
</body>

</html>
