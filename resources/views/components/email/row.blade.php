@props(['label', 'value' => null, 'note' => null, 'color' => '#333333'])
@php($font = "'Segoe UI', Roboto, Helvetica, Arial, sans-serif")
<tr>
    <td align="left" valign="top" style="padding: 6px 8px 6px 16px; font-family: {{ $font }}; font-size: 12px; line-height: 1.45; color: #555555;">
        {{ $label }}
        @if ($note)
            <br><span style="font-size: 11px; color: #8a94a6;">{{ $note }}</span>
        @endif
    </td>
    <td align="right" valign="top" style="padding: 6px 16px 6px 8px; font-family: {{ $font }}; font-size: 12px; line-height: 1.45; font-weight: 700; color: {{ $color }};">{{ $value ?? $slot }}</td>
</tr>
