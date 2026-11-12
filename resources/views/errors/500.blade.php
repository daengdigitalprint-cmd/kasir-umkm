@extends('errors.layout')

@section('title', 'Terjadi Gangguan')
@section('eyebrow', 'Gangguan server')
@section('code', '500')
@section('heading', 'Ada Gangguan di Sistem Kami')

@section('message')
    Bukan salah Anda — ada kendala di sisi server kami dan tim sudah menerima laporannya.
    Data transaksi Anda tetap aman. Silakan muat ulang beberapa saat lagi.
@endsection

@section('actions')
    <a class="btn btn-primary" href="{{ url()->current() }}">Muat Ulang Halaman</a>
    <a class="btn btn-ghost" href="{{ url('/') }}">Ke Beranda</a>
@endsection
