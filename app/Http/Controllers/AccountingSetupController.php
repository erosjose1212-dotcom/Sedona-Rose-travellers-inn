<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\AccountingExportService;
use App\Services\AuditService;
use App\Services\BusinessDayService;
use App\Services\InvoiceService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class AccountingSetupController extends Controller
{
    public function index(BusinessDayService $days): View
    {
        Gate::authorize('review-financial');

        return view('accounting.setup', ['settings' => $days->settings(), 'accounts' => AccountingExportService::ACCOUNTS]);
    }

    public function update(Request $request, AuditService $audit, InvoiceService $invoices): RedirectResponse
    {
        Gate::authorize('manage-accounting-setup');
        $data = $request->validate([
            'legal_name' => 'nullable|string|max:255', 'address' => 'nullable|string|max:500', 'tin' => 'nullable|string|max:100', 'registration' => 'nullable|string|max:255',
            'approval_reference' => 'required|string|max:255', 'tax_mode' => 'required|in:unconfigured,vat_inclusive,non_vat,exempt', 'tax_rate_bps' => 'nullable|integer|min:0|max:10000',
            'invoice_series' => 'nullable|string|max:50|regex:/^[A-Za-z0-9-]+$/', 'next_sequence' => 'nullable|integer|min:1|max:99999999',
            'invoice_enabled' => 'required|boolean', 'export_enabled' => 'required|boolean', 'destination' => 'required|string|max:100',
            'opening_balance_reference' => 'nullable|string|max:255', 'account_mappings' => 'required|array', 'account_mappings.*' => 'nullable|string|max:100',
        ]);
        DB::transaction(function () use ($request, $data, $audit, $invoices): void {
            $row = DB::table('accounting_settings')->where('id', 1)->lockForUpdate()->first();
            $before = json_decode($row->configuration, true, 512, JSON_THROW_ON_ERROR);
            $settings = array_replace($before, $data);
            if ((Invoice::exists() && $settings['invoice_series'] !== $before['invoice_series'])
                || (int) ($settings['next_sequence'] ?? 1) < (int) ($before['next_sequence'] ?? 1)) {
                throw ValidationException::withMessages(['next_sequence' => 'Issued numbers cannot be reused or moved to another series.']);
            }
            $settings['cutoff_hour'] = 6;
            $settings['timezone'] = 'Asia/Manila';
            $settings['account_mappings'] = array_intersect_key($data['account_mappings'], array_flip(AccountingExportService::ACCOUNTS));
            if ($settings['invoice_enabled']) {
                $invoices->requireConfiguration($settings);
            }
            if ($settings['export_enabled'] && (empty($settings['opening_balance_reference']) || $settings['tax_mode'] === 'unconfigured')) {
                throw ValidationException::withMessages(['export_enabled' => 'Approve opening balances and tax treatment before enabling export.']);
            }
            DB::table('accounting_settings')->where('id', 1)->update(['configuration' => json_encode($settings, JSON_THROW_ON_ERROR), 'updated_by' => $request->user()->id, 'updated_at' => now()]);
            $audit->record($request, 'accounting.configuration_changed', $request->user(), ['before' => $before, 'after' => $settings]);
        });

        return back()->with('success', 'Accounting configuration saved.');
    }
}
