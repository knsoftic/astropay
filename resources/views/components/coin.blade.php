@props(['currency', 'size' => null])
@php
    $code = $currency instanceof \App\Enums\AstroPay\Currency ? $currency->value : (string) $currency;
    $symbol = match ($code) {
        'INR' => '₹',
        'PKR' => 'Rs',
        'BDT' => '৳',
        'USDT' => '₮',
        default => mb_substr($code, 0, 1),
    };
@endphp
<span {{ $attributes->merge(['class' => 'coin coin-'.$code.($size === 'sm' ? ' coin-sm' : '')]) }} aria-hidden="true">{{ $symbol }}</span>
