@extends('errors.layout')

@section('title', 'Akses Ditolak')
@section('eyebrow', 'Tidak punya akses')
@section('code', '403')
@section('heading', 'Akses Ditolak')

@section('message')
    Akun Anda tidak punya izin membuka halaman ini. Bila seharusnya bisa,
    minta pemilik toko mengatur ulang peran akun Anda.
@endsection

@section('actions')
    <a class="btn btn-primary" href="{{ url('/') }}">Kembali ke Beranda</a>
    <a class="btn btn-ghost" href="{{ url('/login') }}">Masuk dengan Akun Lain</a>
@endsection
