<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\DeliveryArea;
use App\Models\DeliveryCity;
use App\Rules\Dinars;
use App\Services\Settings;
use App\Services\Store\ActivityLogger;
use App\Services\Store\Pricing\CommerceSettings;
use App\Support\Money;
use App\Support\PanelFormat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

/**
 * Where the shop delivers and what it charges. The numbers set here are the
 * ones the pricing engine reads at checkout — nothing else decides a fee.
 */
class DeliveryController extends Controller
{
    public function __construct(
        protected Settings $settings,
        protected CommerceSettings $commerce,
        protected ActivityLogger $log,
    ) {}

    public function index(): View
    {
        return view('panel.delivery.index', [
            'cities' => DeliveryCity::query()
                ->withCount(['areas', 'areas as active_areas_count' => fn ($query) => $query->where('is_active', true)])
                ->orderBy('sort_order')
                ->orderBy('id')
                ->get(),
            'deliveryFee' => PanelFormat::amount($this->commerce->deliveryFeeFils()),
            'freeShipping' => PanelFormat::amount($this->commerce->freeShippingThresholdFils()),
            'minimumOrder' => PanelFormat::amount($this->commerce->minimumOrderFils()),
        ]);
    }

    public function updateSettings(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'delivery_fee' => ['required', new Dinars],
            'free_shipping' => ['nullable', new Dinars],
            'minimum_order' => ['nullable', new Dinars],
        ]);

        $this->settings->save([
            'commerce.delivery_fee_fils' => (int) Money::parseFils($data['delivery_fee']),
            'commerce.free_shipping_fils' => filled($data['free_shipping'] ?? null) ? (int) Money::parseFils($data['free_shipping']) : 0,
            'commerce.minimum_order_fils' => filled($data['minimum_order'] ?? null) ? (int) Money::parseFils($data['minimum_order']) : 0,
        ]);

        $this->log->record($request->user('staff'), 'delivery.settings_updated', null, [
            'delivery_fee_fils' => $this->commerce->deliveryFeeFils(),
            'free_shipping_fils' => $this->commerce->freeShippingThresholdFils(),
            'minimum_order_fils' => $this->commerce->minimumOrderFils(),
        ], __('panel.modules.delivery'));

        return back()->with('status', __('panel.delivery.settingsSaved'));
    }

    public function storeCity(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:190'],
            'name_en' => ['required', 'string', 'max:190'],
        ]);

        $city = DeliveryCity::query()->create([
            'name_ar' => trim($data['name_ar']),
            'name_en' => trim($data['name_en']),
            'sort_order' => (int) DeliveryCity::query()->max('sort_order') + 1,
            'is_active' => true,
        ]);

        $this->log->record($request->user('staff'), 'delivery.city_created', $city);

        return redirect()->route('panel.delivery.cities.edit', $city)->with('status', __('panel.delivery.cityCreated'));
    }

    public function editCity(DeliveryCity $city): View
    {
        $city->load('areas');

        return view('panel.delivery.city', [
            'city' => $city,
            'areas' => $city->areas->sortBy(fn (DeliveryArea $area) => [$area->sort_order, $area->id])->values(),
            'defaultFee' => PanelFormat::amount($this->commerce->deliveryFeeFils()),
        ]);
    }

    public function updateCity(Request $request, DeliveryCity $city): RedirectResponse
    {
        $data = $request->validate([
            'name_ar' => ['required', 'string', 'max:190'],
            'name_en' => ['required', 'string', 'max:190'],
            'is_active' => ['nullable', 'boolean'],
            'areas' => ['nullable', 'array', 'max:500'],
            'areas.*.name_ar' => ['required', 'string', 'max:190'],
            'areas.*.name_en' => ['required', 'string', 'max:190'],
            'areas.*.fee' => ['nullable', new Dinars],
            'areas.*.is_active' => ['nullable', 'boolean'],
            'areas.*.remove' => ['nullable', 'boolean'],
            'new_areas' => ['nullable', 'array', 'max:50'],
            'new_areas.*.name_ar' => ['required', 'string', 'max:190'],
            'new_areas.*.name_en' => ['required', 'string', 'max:190'],
            'new_areas.*.fee' => ['nullable', new Dinars],
        ]);

        DB::transaction(function () use ($city, $data, $request): void {
            $city->update([
                'name_ar' => trim($data['name_ar']),
                'name_en' => trim($data['name_en']),
                'is_active' => $request->boolean('is_active'),
            ]);

            // Only this city's own areas can be touched, whatever ids arrive.
            $existing = $city->areas()->get()->keyBy('id');

            foreach ($data['areas'] ?? [] as $id => $row) {
                $area = $existing->get((int) $id);

                if ($area === null) {
                    continue;
                }

                if (! empty($row['remove'])) {
                    $area->delete();

                    continue;
                }

                $area->update([
                    'name_ar' => trim($row['name_ar']),
                    'name_en' => trim($row['name_en']),
                    'fee_fils' => filled($row['fee'] ?? null) ? Money::parseFils((string) $row['fee']) : null,
                    'is_active' => ! empty($row['is_active']),
                ]);
            }

            $next = (int) $city->areas()->max('sort_order') + 1;

            foreach ($data['new_areas'] ?? [] as $row) {
                $city->areas()->create([
                    'name_ar' => trim($row['name_ar']),
                    'name_en' => trim($row['name_en']),
                    'fee_fils' => filled($row['fee'] ?? null) ? Money::parseFils((string) $row['fee']) : null,
                    'sort_order' => $next++,
                    'is_active' => true,
                ]);
            }
        });

        $this->log->record($request->user('staff'), 'delivery.city_updated', $city);

        return redirect()->route('panel.delivery.cities.edit', $city)->with('status', __('panel.delivery.citySaved'));
    }

    /** Sets one fee for every area of a city, or — left blank — puts them all back on the default. */
    public function setCityFee(Request $request, DeliveryCity $city): RedirectResponse
    {
        $data = $request->validate(['fee' => ['nullable', new Dinars]]);

        $fee = filled($data['fee'] ?? null) ? Money::parseFils((string) $data['fee']) : null;

        $city->areas()->update(['fee_fils' => $fee]);

        $this->log->record($request->user('staff'), 'delivery.city_fee_set', $city, ['fee_fils' => $fee]);

        return back()->with('status', $fee === null ? __('panel.delivery.feeReset') : __('panel.delivery.feeSet'));
    }

    public function destroyCity(Request $request, DeliveryCity $city): RedirectResponse
    {
        $label = $city->name_ar;

        $city->delete();

        $this->log->record($request->user('staff'), 'delivery.city_deleted', null, [], $label);

        return redirect()->route('panel.delivery.index')->with('status', __('panel.delivery.cityDeleted'));
    }
}
