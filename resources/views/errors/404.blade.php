@extends('errors.layout')

@section('title', 'Halaman Tidak Ditemukan')
@section('eyebrow', 'Halaman tidak ada')
@section('code', '404')
@section('heading', 'Halaman Tidak Ditemukan')

@section('message')
    Alamat yang Anda buka sudah dipindahkan, diganti, atau memang tidak pernah ada.
    Coba periksa kembali tautannya, atau mulai dari salah satu halaman berikut.
@endsection

@section('actions')
    <a class="btn btn-primary" href="{{ url('/') }}">Kembali ke Beranda</a>
    <a class="btn btn-ghost" href="{{ url('/blog') }}">Baca Artikel</a>
@endsection
