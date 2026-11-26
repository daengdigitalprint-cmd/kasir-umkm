{{--
    Bentuk hiasan kartu (murni dekorasi, alt kosong).
    $variant: 'hero'  = kartu lavender, sama dengan hero halaman afiliasi
              'hijau' = kartu hijau muda, sama dengan kartu "Paket tahunan · komisi terbesar"
--}}
@php
    $dir = asset('sastik-assets/img/shape');
@endphp
<div class="kt-shapes" aria-hidden="true">
    @if (($variant ?? 'hero') === 'hijau')
        <span class="s1"><img width="195" height="337" src="{{ $dir }}/solution_shape05.png" alt="" loading="lazy" decoding="async"></span>
        <span class="s2"><img width="195" height="337" src="{{ $dir }}/solution_shape06.png" alt="" loading="lazy" decoding="async"></span>
        <span class="s3"><img width="264" height="299" src="{{ $dir }}/solution_shape07.png" alt="" loading="lazy" decoding="async"></span>
        <span class="s4"><img width="264" height="300" src="{{ $dir }}/solution_shape08.png" alt="" loading="lazy" decoding="async"></span>
        <span class="s6"><img width="204" height="370" src="{{ $dir }}/circle-blur03.png" alt="" loading="lazy" decoding="async"></span>
        <span class="s7"><img width="204" height="370" src="{{ $dir }}/circle-blur03.png" alt="" loading="lazy" decoding="async"></span>
    @else
        <span class="s1"><img width="195" height="337" src="{{ $dir }}/das_shape03.png" alt="" decoding="async"></span>
        <span class="s2"><img width="195" height="337" src="{{ $dir }}/das_shape04.png" alt="" decoding="async"></span>
        <span class="s3"><img width="255" height="264" src="{{ $dir }}/das_shape01.png" alt="" decoding="async"></span>
        <span class="s4"><img width="255" height="264" src="{{ $dir }}/das_shape02.png" alt="" decoding="async"></span>
        <span class="s5"><img width="1328" height="388" src="{{ $dir }}/circle-blur.webp" alt="" decoding="async"></span>
    @endif
</div>
