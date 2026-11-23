@props(['label' => 'Total', 'value'])
@php($font = "'Segoe UI', Roboto, Helvetica, Arial, sans-serif")
<tr>
    <td colspan="2" style="padding: 8px 16px 2px;">
        <div style="border-top: 2px dotted #c4b5fd; height: 0; font-size: 0; line-height: 0;">&nbsp;</div>
    </td>
</tr>
<tr>
    <td align="left" valign="middle" style="padding: 8px 8px 4px 16px; font-family: {{ $font }}; font-size: 16px; font-weight: 700; color: #333333;">{{ $label }}</td>
    <td align="right" valign="middle" style="padding: 8px 16px 4px 8px; font-family: {{ $font }}; font-size: 16px; font-weight: 700; color: #7943e5;">{{ $value }}</td>
</tr>
