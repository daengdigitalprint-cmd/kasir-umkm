<x-email.layout
    title="Atur Ulang Kata Sandi"
    :name="$name"
    preheader="Tautan untuk mengatur ulang kata sandi akun Kasir UMKM Anda"
    intro="Kami menerima permintaan untuk mengatur ulang kata sandi akun Kasir UMKM Anda."
>
    <x-email.row label="Akun" :value="$email" />
    <x-email.row label="Waktu permintaan" :value="$time" />
    <x-email.total label="Berlaku" :value="$expireMinutes.' menit'" />

    <x-slot:after>
        <x-email.button :url="$url">Atur ulang kata sandi</x-email.button>
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
        Bila Anda tidak meminta reset kata sandi, abaikan email ini. Kata sandi Anda tidak akan berubah.
    </x-slot:closing>
</x-email.layout>
