@props(['series' => [], 'label' => ''])

@php
    use App\Support\PanelFormat;
    use Illuminate\Support\Carbon;

    // Server-drawn, so there is no chart library to load. The scale is only a
    // picture of the figures: the amounts themselves come from the report.
    $width = 720;
    $height = 230;
    $left = 46;
    $top = 10;
    $bottom = 28;
    $plotW = $width - $left - 8;
    $plotH = $height - $top - $bottom;

    $count = max(count($series), 1);
    $highest = max(array_column($series, 'revenue') ?: [0]);
    $magnitude = 10 ** max((int) floor(log10(max($highest, 1000))), 0);
    $ceiling = max((int) (ceil(max($highest, 1000) / $magnitude) * $magnitude), 1000);
    $slot = $plotW / $count;
    $barW = max($slot * 0.62, 2);
    $tick = fn (int $fils): string => rtrim(rtrim(number_format($fils / 1000, 1, '.', ','), '0'), '.');
    $every = $count > 16 ? 5 : 1;
@endphp

<svg class="chart" viewBox="0 0 {{ $width }} {{ $height }}" role="img" aria-label="{{ $label }}" dir="ltr">
    @foreach ([0, 0.5, 1] as $fraction)
        @php $y = $top + $plotH - $plotH * $fraction; @endphp
        <line class="grid-line" x1="{{ $left }}" x2="{{ $width - 8 }}" y1="{{ $y }}" y2="{{ $y }}"/>
        <text x="{{ $left - 8 }}" y="{{ $y + 4 }}" text-anchor="end">{{ $tick((int) ($ceiling * $fraction)) }}</text>
    @endforeach

    @foreach ($series as $index => $day)
        @php
            $date = Carbon::parse($day['date']);
            $barH = $day['revenue'] > 0 ? max($plotH * $day['revenue'] / $ceiling, 2) : 0;
            $x = $left + $slot * $index + ($slot - $barW) / 2;
        @endphp
        @if ($barH > 0)
            <rect class="bar" x="{{ round($x, 2) }}" y="{{ round($top + $plotH - $barH, 2) }}" width="{{ round($barW, 2) }}" height="{{ round($barH, 2) }}" rx="2">
                <title>{{ $date->locale(app()->getLocale())->isoFormat('D MMM') }} — {{ PanelFormat::money($day['revenue']) }} · {{ trans_choice('panel.dashboard.orders', $day['orders']) }}</title>
            </rect>
        @endif
        @if ($index % $every === 0 || $index === $count - 1)
            <text x="{{ round($x + $barW / 2, 2) }}" y="{{ $height - 8 }}" text-anchor="middle">{{ $date->locale(app()->getLocale())->isoFormat('D MMM') }}</text>
        @endif
    @endforeach
</svg>
