<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\User;
use App\Support\PanelFormat;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;

class ActivityLogController extends Controller
{
    public function index(Request $request): View
    {
        $staff = (string) $request->query('staff', '');
        $area = (string) $request->query('area', '');
        $from = $this->date((string) $request->query('from', ''));
        $to = $this->date((string) $request->query('to', ''));
        $zone = PanelFormat::timezone();

        $entries = ActivityLog::query()
            ->with('staff')
            ->when(ctype_digit($staff), fn (Builder $query) => $query->where('user_id', (int) $staff))
            ->when($area !== '' && preg_match('/^[a-z_]+$/', $area) === 1, fn (Builder $query) => $query->where('action', 'like', $area.'.%'))
            ->when($from !== '', fn (Builder $query) => $query->where('created_at', '>=', Carbon::parse($from, $zone)->startOfDay()->timezone(config('app.timezone'))))
            ->when($to !== '', fn (Builder $query) => $query->where('created_at', '<=', Carbon::parse($to, $zone)->endOfDay()->timezone(config('app.timezone'))))
            ->latest('id')
            ->paginate(30)
            ->withQueryString();

        return view('panel.log.index', [
            'entries' => $entries,
            'members' => User::query()->orderBy('name')->get(['id', 'name']),
            'areas' => ActivityLog::query()
                ->selectRaw("DISTINCT substr(action, 1, instr(action, '.') - 1) AS area")
                ->toBase()
                ->pluck('area')
                ->filter()
                ->sort()
                ->values(),
            'filters' => ['staff' => ctype_digit($staff) ? $staff : '', 'area' => $area, 'from' => $from, 'to' => $to],
        ]);
    }

    protected function date(string $value): string
    {
        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1 && strtotime($value) !== false ? $value : '';
    }
}
