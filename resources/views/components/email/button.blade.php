@props(['url'])
@php($font = "'Segoe UI', Roboto, Helvetica, Arial, sans-serif")
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr>
        <td align="center" style="padding-top: 20px;">
            <a href="{{ $url }}" target="_blank" style="display: inline-block; padding: 11px 24px; border-radius: 8px; background: #7943e5; color: #ffffff; font-family: {{ $font }}; font-size: 13px; font-weight: 700; text-decoration: none;">{{ $slot }}</a>
        </td>
    </tr>
</table>
