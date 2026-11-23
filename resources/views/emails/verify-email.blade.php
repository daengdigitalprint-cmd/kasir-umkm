<x-email.layout
    title="Verifikasi Email"
    :name="$name"
    preheader="Konfirmasi alamat email akun Kasir UMKM Anda"
    intro="Satu langkah lagi: konfirmasi bahwa alamat email ini milik Anda."
>
    <x-email.row label="Akun" :value="$email" />
    <x-email.row label="Waktu" :value="$time" />

    <x-slot:after>
        <x-email.button :url="$url">Verifikasi email</x-email.button>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
            <tr>
                <td style="padding-top: 14px; font-family: 'Segoe UI', Roboto, Helvetica, Arial, sans-serif; font-size: 11px; line-height: 1.5; color: #666666; word-break: break-all;">
                    Bila tombol tidak bisa diklik, salin tautan ini ke browser:<br>
                    <a href="{{ $url }}" style="color: #7943e5;">{{ $url }}</a>
                </td>
            </tr>
        </table>
    </x-slot:after>

    <x-slot:closing>
        Bila Anda tidak membuat akun Kasir UMKM, abaikan email ini.
    </x-slot:closing>
</x-email.layout>
