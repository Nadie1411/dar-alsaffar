<?php

namespace App\Http\Controllers\Panel;

use App\Http\Controllers\Controller;
use App\Services\Store\Reports\SalesReport;
use App\Support\Csv;
use App\Support\Money;
use App\Support\PanelFormat;
use Carbon\CarbonInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Sales over a period: how much, on what, to where, and how it was paid.
 */
class ReportController extends Controller
{
    /** The longest period a report covers, in days. */
    protected const MAX_DAYS = 366;

    public function index(Request $request, SalesReport $report): View
    {
        [$from, $to, $preset] = $this->range($request);

        return view('panel.reports.index', [
            'from' => $from,
            'to' => $to,
            'preset' => $preset,
            'summary' => $report->summary($from, $to),
            'series' => $report->daily($from, $to),
            'products' => $report->topProducts($from, $to, 15),
            'methods' => $report->byPaymentMethod($from, $to),
            'cities' => $report->byCity($from, $to),
            'statuses' => $report->byStatus($from, $to),
        ]);
    }

    public function export(Request $request, SalesReport $report): StreamedResponse
    {
        [$from, $to] = $this->range($request);

        $rows = collect($report->daily($from, $to))->map(fn (array $day) => [
            $day['date'],
            $day['orders'],
            number_format($day['revenue'] / Money::FILS, 3, '.', ''),
        ]);

        return Csv::download(
            'sales-'.$from->format('Y-m-d').'-to-'.$to->format('Y-m-d').'.csv',
            [__('panel.common.date'), __('panel.reports.orders'), __('panel.reports.revenue')],
            $rows,
        );
    }

    /**
     * The period asked for, in the shop's own time: a preset, or two dates.
     * Anything unreadable falls back to the last 30 days, and a period longer
     * than a year is cut to a year.
     *
     * @return array{0:CarbonInterface,1:CarbonInterface,2:string}
     */
    protected function range(Request $request): array
    {
        $zone = PanelFormat::timezone();
        $now = now($zone);
        $preset = (string) $request->query('range', '30d');

        $period = match ($preset) {
            'today' => [$now->copy()->startOfDay(), $now->copy()->endOfDay()],
            '7d' => [$now->copy()->subDays(6)->startOfDay(), $now->copy()->endOfDay()],
            'month' => [$now->copy()->startOfMonth(), $now->copy()->endOfDay()],
            'last_month' => [$now->copy()->subMonthNoOverflow()->startOfMonth(), $now->copy()->subMonthNoOverflow()->endOfMonth()],
            'custom' => $this->custom($request, $zone),
            default => null,
        };

        if ($period === null) {
            $preset = '30d';
            $period = [$now->copy()->subDays(29)->startOfDay(), $now->copy()->endOfDay()];
        }

        [$from, $to] = $period;

        if ($from->diffInDays($to) > self::MAX_DAYS) {
            $from = $to->copy()->subDays(self::MAX_DAYS)->startOfDay();
        }

        return [$from, $to, $preset];
    }

    /** @return array{0:Carbon,1:Carbon}|null */
    protected function custom(Request $request, string $zone): ?array
    {
        $from = (string) $request->query('from', '');
        $to = (string) $request->query('to', '');

        foreach ([$from, $to] as $date) {
            if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1 || strtotime($date) === false) {
                return null;
            }
        }

        $start = Carbon::parse($from, $zone)->startOfDay();
        $end = Carbon::parse($to, $zone)->endOfDay();

        return $end->lt($start) ? null : [$start, $end];
    }
}
