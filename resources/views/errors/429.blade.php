@extends('errors.layout')

@section('title', 'Terlalu Banyak Permintaan')
@section('eyebrow', 'Terlalu cepat')
@section('code', '429')
@section('heading', 'Terlalu Banyak Permintaan')

@section('message')
    Permintaan dari perangkat Anda masuk terlalu cepat sehingga kami tahan sementara
    demi menjaga sistem tetap stabil. Tunggu sebentar, lalu coba lagi.
@endsection

@section('actions')
    <a class="btn btn-primary" href="{{ url()->current() }}">Coba Lagi</a>
    <a class="btn btn-ghost" href="{{ url('/') }}">Ke Beranda</a>
@endsection
