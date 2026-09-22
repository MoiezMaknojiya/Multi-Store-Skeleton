{{-- The one thing an email asks the reader to do, built from a table cell rather than an image: it survives
     blocked images, and Outlook — which ignores border-radius — still draws a solid blue block with white
     words on it. The padding lives on the <a> so the whole button is the link. --}}
@props(['url'])
<table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center" style="margin:0 auto;">
    <tr>
        <td align="center" bgcolor="#2563eb" style="background-color:#2563eb; border-radius:8px;">
            <a href="{{ $url }}" target="_blank" rel="noopener"
               style="display:inline-block; padding:14px 34px; font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif; font-size:16px; font-weight:600; line-height:20px; color:#ffffff; text-decoration:none; border-radius:8px;">
                {{ $slot }}
            </a>
        </td>
    </tr>
</table>
