<?php

namespace App\Http\Controllers;

use App\Services\ManagementReportService;
use App\Services\ManagementWorkbookService;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ManagementReportController extends Controller
{
    public function __construct(private ManagementReportService $reports, private ManagementWorkbookService $workbooks) {}

    public function index(Request $request): View|StreamedResponse
    {
        $filters = $request->validate([
            'report' => ['nullable', Rule::in(array_keys(ManagementReportService::REPORTS))],
            'range' => 'nullable|in:today,yesterday,this_week,this_month,last_month,custom',
            'report_date' => 'nullable|date_format:Y-m-d',
            'start_date' => 'required_if:range,custom|nullable|date_format:Y-m-d',
            'end_date' => 'required_if:range,custom|nullable|date_format:Y-m-d|after_or_equal:start_date',
            'shift' => 'nullable|in:all,day,night',
            'format' => 'nullable|in:preview,xlsx',
            'calendar' => 'nullable|boolean',
            'finance_day' => 'nullable|boolean',
        ]);
        if (($filters['calendar'] ?? false) && ($filters['shift'] ?? 'all') !== 'all') {
            throw ValidationException::withMessages(['shift' => 'Calendar date reports include both shifts. Use business dates for individual shifts.']);
        }
        if (($filters['finance_day'] ?? false) && (($filters['calendar'] ?? false) || ($filters['shift'] ?? 'all') !== 'all')) {
            throw ValidationException::withMessages(['shift' => 'Daily Finance exports use complete accounting days.']);
        }
        if (($filters['range'] ?? '') === 'custom' && CarbonImmutable::parse($filters['start_date'])->diffInDays(CarbonImmutable::parse($filters['end_date'])) >= 366) {
            throw ValidationException::withMessages(['end_date' => 'Choose up to 366 dates per export.']);
        }
        $period = $this->reports->period($filters);
        $type = $filters['report'] ?? 'shift_forms';
        $report = $this->reports->build($type, $period);
        if (($filters['format'] ?? '') === 'xlsx') {
            $book = $this->workbooks->build($report);
            $property = str_contains(strtolower($report['property']), 'rose') ? 'Rose' : 'Court';
            $name = trim(preg_replace('/[^A-Za-z0-9]+/', '_', $report['title']), '_');
            $filename = 'Sedona_'.$property.'_'.$name.'_'.$period['start_date'].'_to_'.$period['end_date'].'_'.$period['shift'].'.xlsx';

            return response()->streamDownload(function () use ($book): void {
                try {
                    (new Xlsx($book))->save('php://output');
                } finally {
                    $book->disconnectWorksheets();
                }
            }, $filename, ['Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'Cache-Control' => 'private, no-store']);
        }

        $rangePresets = [];
        foreach (['today' => 'Today', 'yesterday' => 'Yesterday', 'this_week' => 'This week', 'this_month' => 'This month', 'last_month' => 'Last month'] as $key => $label) {
            $preset = $this->reports->period(['range' => $key, 'calendar' => $period['calendar'], 'finance_day' => $period['finance_day']]);
            $rangePresets[$key] = ['label' => $label, 'start' => $preset['start_date'], 'end' => $preset['end_date']];
        }
        $selectedRange = $period['range'];
        if (! isset($rangePresets[$selectedRange]) || $rangePresets[$selectedRange]['start'] !== $period['start_date'] || $rangePresets[$selectedRange]['end'] !== $period['end_date']) {
            $selectedRange = 'custom';
        }

        return view('accounting.reports', ['report' => $report, 'period' => $period, 'types' => ManagementReportService::REPORTS,
            'descriptions' => ManagementReportService::DESCRIPTIONS, 'rangePresets' => $rangePresets, 'selectedRange' => $selectedRange]);
    }
}
