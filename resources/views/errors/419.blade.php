@extends('errors.layout')

@section('title', 'Sesi Kedaluwarsa')
@section('eyebrow', 'Sesi berakhir')
@section('code', '419')
@section('heading', 'Sesi Anda Sudah Berakhir')

@section('message')
    Halaman dibiarkan terbuka terlalu lama, jadi sesi keamanannya kedaluwarsa.
    Silakan masuk kembali, lalu ulangi langkah terakhir Anda.
@endsection

@section('actions')
    <a class="btn btn-primary" href="{{ url('/login') }}">Masuk Lagi</a>
    <a class="btn btn-ghost" href="{{ url('/') }}">Ke Beranda</a>
@endsection
