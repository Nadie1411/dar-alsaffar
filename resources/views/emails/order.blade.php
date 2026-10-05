@php
    use App\Enums\OrderStatus;
    use App\Support\Money;
    use App\Support\Phone;

    // Written for mail clients, which ignore most modern CSS: tables, inline
    // styles and an explicit direction on every block that holds text.
    $dir = $lang === 'ar' ? 'rtl' : 'ltr';
    $start = $dir === 'rtl' ? 'right' : 'left';
    $end = $dir === 'rtl' ? 'left' : 'right';
    $money = fn (int $fils) => Money::format(Money::fromFils($fils), Money::KWD);
    $pick = fn (?array $map) => (string) (($map[$lang] ?? '') ?: ($map['ar'] ?? '') ?: ($map['en'] ?? ''));
    $brand = config('brand.name.'.$lang, config('brand.name.ar'));
    $placed = ($order->placed_at ?? $order->created_at)->copy()->timezone(config('store.timezone', 'Asia/Kuwait'))->locale($lang)->isoFormat('D MMMM YYYY، h:mm A');
    $contact = $contact ?? [];
    $whatsapp = preg_replace('/\D+/', '', (string) ($contact['whatsapp'] ?? ''));
@endphp
<!DOCTYPE html>
<html lang="{{ $lang }}" dir="{{ $dir }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light">
    <title>{{ $headline }}</title>
</head>
<body style="margin:0;padding:0;background:#f7f3ea;color:#0e100f;font-family:Tahoma,'Segoe UI',Arial,sans-serif;font-size:15px;line-height:1.7">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f7f3ea">
    <tr>
        <td align="center" style="padding:24px 12px">
            <table role="presentation" width="600" cellpadding="0" cellspacing="0" dir="{{ $dir }}" style="width:100%;max-width:600px;background:#ffffff;border:1px solid #e7e2d6;border-radius:8px;overflow:hidden">
                <tr>
                    <td align="center" style="background:#00603a;padding:22px 24px">
                        <span style="font-family:Georgia,'Times New Roman',serif;font-size:24px;color:#f7f0dc;letter-spacing:.5px">{{ $brand }}</span>
                    </td>
                </tr>

                <tr>
                    <td dir="{{ $dir }}" style="padding:28px 28px 8px;text-align:{{ $start }}">
                        <h1 style="margin:0 0 10px;font-size:22px;line-height:1.35;color:#00301d">{{ $headline }}</h1>
                        @if ($greeting ?? null)<p style="margin:0 0 8px">{{ $greeting }}</p>@endif
                        <p style="margin:0 0 6px">{{ $lead }}</p>
                        @if ($note ?? null)<p style="margin:8px 0 0;color:#8f2f2f">{{ $note }}</p>@endif
                    </td>
                </tr>

                <tr>
                    <td dir="{{ $dir }}" style="padding:12px 28px;text-align:{{ $start }}">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#fdfbf7;border:1px solid #efe8da;border-radius:6px">
                            <tr>
                                <td style="padding:12px 16px;text-align:{{ $start }}">
                                    <span style="color:#5d635f;font-size:13px">{{ __('storefront.mail.orderNumber') }}</span><br>
                                    <strong style="font-size:17px;direction:ltr;unicode-bidi:isolate">{{ $order->number }}</strong>
                                </td>
                                <td style="padding:12px 16px;text-align:{{ $end }}">
                                    <span style="color:#5d635f;font-size:13px">{{ __('storefront.mail.placedOn') }}</span><br>
                                    <span>{{ $placed }}</span>
                                </td>
                            </tr>
                        </table>
                    </td>
                </tr>

                @if (($audience ?? 'customer') === 'staff')
                    <tr>
                        <td dir="{{ $dir }}" style="padding:4px 28px 8px;text-align:{{ $start }}">
                            <strong>{{ __('panel.orders.customer') }}</strong><br>
                            {{ $order->customer_name }} ·
                            <span style="direction:ltr;unicode-bidi:isolate">{{ $order->customer_phone }}</span>
                            @if ($order->customer_email)<br><span style="direction:ltr;unicode-bidi:isolate">{{ $order->customer_email }}</span>@endif
                        </td>
                    </tr>
                @endif

                <tr>
                    <td dir="{{ $dir }}" style="padding:8px 28px 4px;text-align:{{ $start }}">
                        <strong>{{ ($audience ?? 'customer') === 'staff' ? __('panel.orders.items') : __('storefront.mail.items') }}</strong>
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin-top:6px">
                            @foreach ($order->items as $item)
                                <tr>
                                    <td style="padding:8px 0;border-bottom:1px solid #efe8da;text-align:{{ $start }}">
                                        {{ $item->localized('name', $lang) }}
                                        <span style="color:#5d635f">× {{ $item->quantity }}</span>
                                        @foreach ($item->options ?? [] as $option)
                                            <br><span style="color:#5d635f;font-size:13px">{{ $pick($option['group'] ?? null) }}:
                                                {{ collect($option['values'] ?? [])->map(fn ($value) => $pick($value['name'] ?? null).(($value['quantity'] ?? 1) > 1 ? ' × '.$value['quantity'] : ''))->implode('، ') }}</span>
                                        @endforeach
                                    </td>
                                    <td style="padding:8px 0;border-bottom:1px solid #efe8da;text-align:{{ $end }};white-space:nowrap">{{ $money($item->total_fils) }}</td>
                                </tr>
                            @endforeach
                        </table>
                    </td>
                </tr>

                <tr>
                    <td dir="{{ $dir }}" style="padding:4px 28px 12px;text-align:{{ $start }}">
                        <table role="presentation" width="100%" cellpadding="0" cellspacing="0">
                            <tr><td style="padding:4px 0">{{ __('storefront.cart.subtotal') }}</td><td style="padding:4px 0;text-align:{{ $end }}">{{ $money($order->subtotal_fils) }}</td></tr>
                            @if ($order->discount_fils > 0)
                                <tr><td style="padding:4px 0">{{ __('storefront.cart.discount') }}@if ($order->voucher_code) ({{ $order->voucher_code }})@endif</td><td style="padding:4px 0;text-align:{{ $end }};color:#00603a">− {{ $money($order->discount_fils) }}</td></tr>
                            @endif
                            <tr><td style="padding:4px 0">{{ __('storefront.cart.delivery') }}</td><td style="padding:4px 0;text-align:{{ $end }}">{{ $order->delivery_fee_fils > 0 ? $money($order->delivery_fee_fils) : __('storefront.mail.free') }}</td></tr>
                            @if ($order->addons_total_fils > 0)
                                <tr><td style="padding:4px 0">{{ __('storefront.checkout.addons') }}</td><td style="padding:4px 0;text-align:{{ $end }}">{{ $money($order->addons_total_fils) }}</td></tr>
                            @endif
                            @if ($order->cod_fee_fils > 0)
                                <tr><td style="padding:4px 0">{{ __('storefront.cart.codFee') }}</td><td style="padding:4px 0;text-align:{{ $end }}">{{ $money($order->cod_fee_fils) }}</td></tr>
                            @endif
                            <tr><td style="padding:10px 0 4px;border-top:2px solid #e7e2d6"><strong>{{ __('storefront.cart.total') }}</strong></td><td style="padding:10px 0 4px;border-top:2px solid #e7e2d6;text-align:{{ $end }}"><strong style="font-size:17px">{{ $money($order->total_fils) }}</strong></td></tr>
                        </table>
                    </td>
                </tr>

                <tr>
                    <td dir="{{ $dir }}" style="padding:4px 28px 8px;text-align:{{ $start }}">
                        <strong>{{ __('storefront.mail.deliveryTo') }}</strong><br>
                        {{ $order->localizedAddress($lang) }}
                        @if ($order->floor || $order->apartment)
                            <br>{{ $order->floor ? __('storefront.checkout.floor').' '.$order->floor : '' }}{{ $order->floor && $order->apartment ? ' · ' : '' }}{{ $order->apartment ? __('storefront.checkout.apartment').' '.$order->apartment : '' }}
                        @endif
                        <br><br>
                        <strong>{{ __('storefront.mail.payment') }}</strong><br>
                        {{ $paymentLine }}
                        @if ($order->notes)
                            <br><br><strong>{{ ($audience ?? 'customer') === 'staff' ? __('panel.orders.customerNotes') : __('storefront.mail.notes') }}</strong><br>{{ $order->notes }}
                        @endif
                    </td>
                </tr>

                @if ($button ?? null)
                    <tr>
                        <td dir="{{ $dir }}" align="{{ $start }}" style="padding:12px 28px 8px;text-align:{{ $start }}">
                            <a href="{{ $button['url'] }}" style="display:inline-block;background:#00603a;color:#ffffff;text-decoration:none;padding:11px 22px;border-radius:5px;font-weight:bold">{{ $button['label'] }}</a>
                        </td>
                    </tr>
                @endif

                @if (($audience ?? 'customer') === 'customer')
                    <tr>
                        <td dir="{{ $dir }}" style="padding:16px 28px 24px;text-align:{{ $start }};color:#5d635f;font-size:13px">
                            @if (filled($contact['phone'] ?? null) || $whatsapp !== '')
                                {{ __('storefront.mail.questions') }}
                                @if (filled($contact['phone'] ?? null))
                                    {{ __('storefront.mail.call') }}: <span style="direction:ltr;unicode-bidi:isolate">{{ $contact['phone'] }}</span>
                                @endif
                                @if ($whatsapp !== '')
                                    · <a href="https://wa.me/{{ $whatsapp }}" style="color:#00603a">{{ __('storefront.mail.whatsapp') }}</a>
                                @endif
                                <br><br>
                            @endif
                            {{ __('storefront.mail.footer', ['email' => $order->customer_email]) }}
                        </td>
                    </tr>
                @endif
            </table>
        </td>
    </tr>
</table>
</body>
</html>
