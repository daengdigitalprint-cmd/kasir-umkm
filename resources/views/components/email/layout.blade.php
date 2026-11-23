@props([
    'title',
    'name' => null,
    'intro' => null,
    'preheader' => null,
    'heading' => null,
])
@php
    // Tampilan email bersama Kasir UMKM: kontainer sempit, header ungu brand,
    // badan abu kebiruan dengan kartu "tiket" putih, footer ungu berisi kontak.
    // Semua gaya inline + tabel supaya aman di Gmail/Outlook.
    $brand = '#7943e5';
    $brandLine = '#a78bfa';
    $appUrl = rtrim((string) config('app.url'), '/');
    $font = "'Segoe UI', Roboto, Helvetica, Arial, sans-serif";
@endphp
<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>{{ $title }}</title>
</head>
<body style="margin: 0; padding: 0; background: #ffffff; font-family: {{ $font }}; color: #333333; -webkit-text-size-adjust: 100%;">
@if ($preheader)
    <div style="display: none; max-height: 0; overflow: hidden; font-size: 1px; line-height: 1px; color: #ffffff; opacity: 0;">{{ $preheader }}</div>
@endif
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="background: #ffffff;">
    <tr>
        <td align="center" style="padding: 20px 8px;">
            <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="max-width: 410px; border: 1px solid #cccccc; border-collapse: collapse;">
                {{-- Header --}}
                <tr>
                    <td align="left" bgcolor="{{ $brand }}" style="background: {{ $brand }}; padding: 14px 16px;">
                        <a href="{{ $appUrl }}" target="_blank" style="text-decoration: none;">
                            <img src="{{ $appUrl }}/email/logo-kasir-umkm-putih.png" width="96" height="64" alt="Kasir UMKM by MaccaPOS" style="display: block; border: 0; width: 96px; height: 64px;">
                        </a>
                    </td>
                </tr>

                {{-- Badan --}}
                <tr>
                    <td bgcolor="#eef2f6" style="background: #eef2f6; padding: 32px 20px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="font-family: {{ $font }}; font-size: 14px; color: #333333;">Halo, <strong>{{ $name ?: 'Pengguna Kasir UMKM' }}</strong></td>
                            </tr>
                            @if ($intro)
                                <tr>
                                    <td style="padding-top: 6px; font-family: {{ $font }}; font-size: 12px; line-height: 1.5; color: #333333;">{{ $intro }}</td>
                                </tr>
                            @endif
                        </table>

                        {{-- Kartu tiket --}}
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="margin-top: 20px; background: #ffffff; border-radius: 12px; border-collapse: separate;">
                            <tr>
                                <td colspan="2" style="height: 5px; background: {{ $brand }}; border-radius: 12px 12px 0 0; font-size: 0; line-height: 0;">&nbsp;</td>
                            </tr>
                            <tr>
                                <td colspan="2" align="center" style="padding: 18px 16px 10px; font-family: {{ $font }}; font-size: 16px; font-weight: 700; color: #333333;">{{ $heading ?? $title }}</td>
                            </tr>
                            {{ $slot }}
                            <tr>
                                <td colspan="2" style="height: 12px; font-size: 0; line-height: 0;">&nbsp;</td>
                            </tr>
                        </table>

                        @isset($after)
                            {{ $after }}
                        @endisset

                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td style="padding-top: 20px; font-family: {{ $font }}; font-size: 12px; line-height: 1.6; color: #333333;">
                                    @isset($closing)
                                        {{ $closing }}
                                    @else
                                        Semoga informasi ini bermanfaat. Bila ada pertanyaan, hubungi kami lewat WhatsApp atau email di bawah ini.
                                    @endisset
                                    <br><br>Terima kasih,<br><strong>Tim Kasir UMKM</strong>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                {{-- Footer --}}
                <tr>
                    <td bgcolor="{{ $brand }}" style="background: {{ $brand }}; padding: 24px 20px;">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
                            <tr>
                                <td valign="top" width="50%" style="padding-right: 8px; font-family: {{ $font }}; font-size: 11px; line-height: 1.7; color: #ffffff;">
                                    <strong style="color: #ffffff;">Aplikasi Kasir UMKM</strong><br>
                                    <a href="https://play.google.com/store/apps/details?id=com.kasirumkm.idea" target="_blank" style="color: #ffffff; text-decoration: none;">Unduh di Google Play</a><br>
                                    <a href="{{ $appUrl }}" target="_blank" style="color: #ffffff; text-decoration: none;">{{ preg_replace('#^https?://#', '', $appUrl) }}</a>
                                </td>
                                <td valign="top" width="50%" style="padding-left: 8px; font-family: {{ $font }}; font-size: 11px; line-height: 1.7; color: #ffffff;">
                                    <strong style="color: #ffffff;">Hubungi Kami</strong><br>
                                    WhatsApp <a href="https://wa.me/6285242766676" target="_blank" style="color: #ffffff; text-decoration: none;">0852-4276-6676</a><br>
                                    <a href="mailto:info@kasirumkm.web.id" style="color: #ffffff; text-decoration: none;">info@kasirumkm.web.id</a>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="2" style="padding-top: 16px;">
                                    <div style="height: 1px; background: {{ $brandLine }}; font-size: 0; line-height: 0;">&nbsp;</div>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="2" align="center" style="padding-top: 14px; font-family: {{ $font }}; font-size: 11px; line-height: 1.6; color: #e4d9fb;">
                                    Email ini dihasilkan otomatis oleh sistem dan tidak perlu dibalas.<br>
                                    &copy; {{ now()->year }} Kasir UMKM by MaccaPOS
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
