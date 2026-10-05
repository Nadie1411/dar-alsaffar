<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Http\Requests\Panel\AddonRequest;
use App\Models\ServiceAddon;
use App\Services\Store\ActivityLogger;
use App\Services\Store\Uploads;
use App\Support\Money;
use App\Support\PanelFormat;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Extras a customer can add to an order for a fee, such as gift wrapping.
 */
class AddonController extends Controller
{
    public function __construct(
        protected Uploads $uploads,
        protected ActivityLogger $log,
    ) {}

    public function index(): View
    {
        return view('panel.addons.index', [
            'addons' => ServiceAddon::query()->orderBy('sort_order')->orderBy('id')->get(),
        ]);
    }

    public function create(): View
    {
        return view('panel.addons.form', [
            'addon' => new ServiceAddon(['is_active' => true, 'sort_order' => 0, 'price_fils' => 0]),
            'price' => PanelFormat::amount(0),
        ]);
    }

    public function store(AddonRequest $request): RedirectResponse
    {
        $addon = new ServiceAddon;
        $this->fill($addon, $request);
        $addon->save();

        $this->log->record($request->user('staff'), 'addon.created', $addon);

        return redirect()->route('panel.addons.index')->with('status', __('panel.addons.created'));
    }

    public function edit(ServiceAddon $addon): View
    {
        return view('panel.addons.form', [
            'addon' => $addon,
            'price' => PanelFormat::amount($addon->price_fils),
        ]);
    }

    public function update(AddonRequest $request, ServiceAddon $addon): RedirectResponse
    {
        $replaced = $this->fill($addon, $request);
        $addon->save();
        $this->uploads->delete($replaced);

        $this->log->record($request->user('staff'), 'addon.updated', $addon);

        return redirect()->route('panel.addons.index')->with('status', __('panel.addons.updated'));
    }

    public function destroy(Request $request, ServiceAddon $addon): RedirectResponse
    {
        $image = $addon->image;
        $label = $addon->name_ar;

        $addon->delete();
        $this->uploads->delete($image);

        $this->log->record($request->user('staff'), 'addon.deleted', null, [], $label);

        return redirect()->route('panel.addons.index')->with('status', __('panel.addons.deleted'));
    }

    /**
     * @return string|null the picture this add-on had before, to be removed once the change is saved
     */
    protected function fill(ServiceAddon $addon, AddonRequest $request): ?string
    {
        $data = $request->validated();
        $previous = $addon->image;

        $addon->fill([
            'name_ar' => trim($data['name_ar']),
            'name_en' => trim($data['name_en']),
            'description_ar' => filled($data['description_ar'] ?? null) ? trim($data['description_ar']) : null,
            'description_en' => filled($data['description_en'] ?? null) ? trim($data['description_en']) : null,
            'price_fils' => (int) Money::parseFils((string) $data['price']),
            'sort_order' => (int) ($data['sort_order'] ?? 0),
            'is_active' => $request->boolean('is_active'),
        ]);

        if ($request->hasFile('image')) {
            $addon->image = $this->uploads->store($request->file('image'), 'addons');
        } elseif ($request->boolean('remove_image')) {
            $addon->image = null;
        }

        return $previous;
    }
}
