<?php

namespace App\Http\Controllers\Panel;

use App\Enums\OrderStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\VoucherRequest;
use App\Models\Order;
use App\Models\Voucher;
use App\Services\Store\ActivityLogger;
use App\Support\Money;
use App\Support\PanelFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

/**
 * Discount codes. What a code takes off is worked out by the pricing engine
 * at the till; this only says what the codes are.
 */
class VoucherController extends Controller
{
    public function __construct(protected ActivityLogger $log) {}

    public function index(Request $request): View
    {
        $filter = (string) $request->query('status', 'all');
        $filter = in_array($filter, ['all', 'live', 'scheduled', 'expired', 'inactive'], true) ? $filter : 'all';
        $now = now();

        $vouchers = Voucher::query()
            ->when($filter === 'live', fn (Builder $query) => $query->where('is_active', true)
                ->where(fn (Builder $query) => $query->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
                ->where(fn (Builder $query) => $query->whereNull('ends_at')->orWhere('ends_at', '>=', $now)))
            ->when($filter === 'scheduled', fn (Builder $query) => $query->where('is_active', true)->where('starts_at', '>', $now))
            ->when($filter === 'expired', fn (Builder $query) => $query->where('ends_at', '<', $now))
            ->when($filter === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        // How often each code on this page has been used, and what it gave away.
        $usage = Order::query()
            ->whereIn('voucher_code', $vouchers->pluck('code')->filter()->all())
            ->where('status', '!=', OrderStatus::Cancelled->value)
            ->selectRaw('voucher_code, COUNT(*) AS orders, COALESCE(SUM(discount_fils), 0) AS discount')
            ->groupBy('voucher_code')
            ->toBase()
            ->get()
            ->keyBy('voucher_code');

        return view('panel.vouchers.index', [
            'vouchers' => $vouchers,
            'usage' => $usage,
            'filter' => $filter,
        ]);
    }

    public function create(): View
    {
        return view('panel.vouchers.form', $this->formData(new Voucher([
            'type' => Voucher::TYPE_PERCENTAGE, 'is_active' => true, 'is_public' => false,
            'percent' => 0, 'amount_fils' => 0, 'min_subtotal_fils' => 0,
        ])));
    }

    public function store(VoucherRequest $request): RedirectResponse
    {
        $voucher = new Voucher;
        $this->fill($voucher, $request);
        $voucher->save();

        $this->log->record($request->user('staff'), 'voucher.created', $voucher);

        return redirect()->route('panel.vouchers.index')->with('status', __('panel.vouchers.created'));
    }

    public function edit(Voucher $voucher): View
    {
        return view('panel.vouchers.form', $this->formData($voucher));
    }

    public function update(VoucherRequest $request, Voucher $voucher): RedirectResponse
    {
        $this->fill($voucher, $request);
        $voucher->save();

        $this->log->record($request->user('staff'), 'voucher.updated', $voucher);

        return redirect()->route('panel.vouchers.index')->with('status', __('panel.vouchers.updated'));
    }

    public function destroy(Request $request, Voucher $voucher): RedirectResponse
    {
        $label = $voucher->code;

        $voucher->delete();

        $this->log->record($request->user('staff'), 'voucher.deleted', null, [], $label);

        return redirect()->route('panel.vouchers.index')->with('status', __('panel.vouchers.deleted'));
    }

    protected function fill(Voucher $voucher, VoucherRequest $request): void
    {
        $data = $request->validated();
        $type = $data['type'];

        $voucher->fill([
            'code' => $data['code'],
            'name_ar' => trim($data['name_ar']),
            'name_en' => trim($data['name_en']),
            'type' => $type,
            'percent' => $type === Voucher::TYPE_PERCENTAGE
                ? number_format((int) Money::parsePercentBasisPoints((string) $data['percent']) / 100, 2, '.', '')
                : '0.00',
            'amount_fils' => $type === Voucher::TYPE_FIXED ? (int) Money::parseFils((string) $data['amount']) : 0,
            'max_discount_fils' => $type === Voucher::TYPE_PERCENTAGE && filled($data['max_discount'] ?? null)
                ? Money::parseFils((string) $data['max_discount'])
                : null,
            'min_subtotal_fils' => filled($data['min_subtotal'] ?? null) ? (int) Money::parseFils((string) $data['min_subtotal']) : 0,
            'starts_at' => $this->moment($data['starts_at'] ?? null),
            'ends_at' => $this->moment($data['ends_at'] ?? null),
            'usage_limit' => filled($data['usage_limit'] ?? null) ? (int) $data['usage_limit'] : null,
            'usage_limit_per_customer' => filled($data['usage_limit_per_customer'] ?? null) ? (int) $data['usage_limit_per_customer'] : null,
            'is_active' => $request->boolean('is_active'),
            'is_public' => $request->boolean('is_public'),
        ]);
    }

    /**
     * @return array<string,mixed>
     */
    protected function formData(Voucher $voucher): array
    {
        $percent = (float) $voucher->percent;

        return [
            'voucher' => $voucher,
            'percent' => old('percent', $percent > 0 ? rtrim(rtrim(number_format($percent, 2, '.', ''), '0'), '.') : ''),
            'amount' => old('amount', $voucher->amount_fils > 0 ? PanelFormat::amount($voucher->amount_fils) : ''),
            'maxDiscount' => old('max_discount', $voucher->max_discount_fils !== null ? PanelFormat::amount($voucher->max_discount_fils) : ''),
            'minSubtotal' => old('min_subtotal', $voucher->min_subtotal_fils > 0 ? PanelFormat::amount($voucher->min_subtotal_fils) : ''),
            'startsAt' => old('starts_at', PanelFormat::inputDateTime($voucher->starts_at)),
            'endsAt' => old('ends_at', PanelFormat::inputDateTime($voucher->ends_at)),
            'redemptions' => $voucher->exists && $voucher->code !== null
                ? Order::query()->where('voucher_code', $voucher->code)->where('status', '!=', OrderStatus::Cancelled->value)->count()
                : 0,
        ];
    }

    protected function moment(mixed $value): ?Carbon
    {
        return filled($value)
            ? Carbon::createFromFormat('Y-m-d\TH:i', (string) $value, PanelFormat::timezone())->utc()
            : null;
    }
}
