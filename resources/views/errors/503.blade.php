@extends('errors.layout')

@section('title', 'Sedang Pemeliharaan')
@section('eyebrow', 'Pemeliharaan')
@section('code', '503')
@section('heading', 'Sedang Dalam Pemeliharaan')

@section('message')
    Kami sedang memperbarui sistem supaya aplikasi makin cepat dan stabil.
    Prosesnya biasanya hanya beberapa menit. Data Anda aman dan tidak ada yang hilang.
@endsection

@section('actions')
    <a class="btn btn-primary" href="{{ url()->current() }}">Coba Lagi</a>
    <a class="btn btn-ghost" href="https://wa.me/6285242766676" target="_blank" rel="noopener">Hubungi Kami</a>
@endsection
