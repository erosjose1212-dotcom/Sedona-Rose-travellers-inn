<?php

namespace App\Http\Controllers;

use App\Models\AccountingExport;
use App\Models\BusinessDay;
use App\Models\Folio;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\Shift;
use App\Models\StatementLine;
use App\Services\AccountingExportService;
use App\Services\ApprovalService;
use App\Services\AuditService;
use App\Services\BillingService;
use App\Services\BusinessDayService;
use App\Services\FinancialPostingService;
use App\Services\InvoiceService;
use App\Services\ReconciliationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class FinanceControlController extends Controller
{
    public function __construct(private BusinessDayService $days, private ReconciliationService $reconciliation, private AccountingExportService $exports, private AuditService $audit) {}

    public function index(Request $request): View
    {
        Gate::authorize('review-financial');
        $date = $request->validate(['date' => 'nullable|date_format:Y-m-d'])['date'] ?? $this->days->date();

        $day = BusinessDay::whereDate('business_date', $date)->first();

        return view('accounting.controls', ['date' => $date, 'day' => $day, 'snapshot' => $day?->status === 'closed' ? $day->snapshot : $this->days->snapshot($date), 'versions' => $day ? DB::table('business_day_snapshots')->where('business_day_id', $day->id)->orderByDesc('version')->get() : collect(), 'days' => BusinessDay::latest('business_date')->paginate(15)->withQueryString(), 'statements' => StatementLine::latest('posted_at')->paginate(20, ['*'], 'statements_page')->withQueryString(), 'batches' => AccountingExport::latest()->paginate(10, ['*'], 'exports_page')->withQueryString(), 'balances' => Folio::with('guest')->where('status', 'active')->get()->map(fn (Folio $f): array => ['id' => $f->id, 'guest' => $f->guest?->name, 'deposit' => $f->security_deposit, 'balance' => app(BillingService::class)->calculate($f)['finalBalanceToPay']])]);
    }

    public function import(Request $request): RedirectResponse
    {
        $data = $request->validate(['provider' => 'required|in:gcash,bank', 'statement' => 'required|file|max:2048']);
        $count = $this->reconciliation->import($request, $data['provider'], file_get_contents($data['statement']->getRealPath()));

        return back()->with('success', $count.' statement rows imported; duplicates skipped.');
    }

    public function template(): Response
    {
        Gate::authorize('review-financial');

        return response("reference,posted_at,gross_amount,fee_amount,net_amount\n", 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="statement-template.csv"']);
    }

    public function match(Request $request, StatementLine $statement): RedirectResponse
    {
        $data = $request->validate(['entries' => 'required|string|max:2000']);
        $tokens = array_map('trim', explode(',', $data['entries']));
        foreach ($tokens as $token) {
            if (! ctype_digit($token) || (int) $token < 1) {
                throw ValidationException::withMessages(['entries' => 'Use comma-separated movement IDs.']);
            }
        }
        $this->reconciliation->match($request, $statement, array_values(array_unique(array_map('intval', $tokens))));

        return back()->with('success', 'Statement matched to the recorded movements.');
    }

    public function close(Request $request): RedirectResponse
    {
        $data = $request->validate(['business_date' => 'required|date_format:Y-m-d', 'reason' => 'required|string|max:1000']);
        $this->days->close($request, $data['business_date'], $data['reason']);

        return back()->with('success', 'Day closed. Its snapshot and version are preserved.');
    }

    public function reopen(Request $request, BusinessDay $day): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);
        $this->days->reopen($request, $day, $data['reason']);

        return back()->with('success', 'Day reopened. The previous snapshot remains in history.');
    }

    public function export(Request $request, BusinessDay $day): RedirectResponse
    {
        $batch = $this->exports->create($request, $day);

        return back()->with('success', 'Export #'.$batch->id.' prepared for independent review.');
    }

    public function reviewExport(Request $request, AccountingExport $batch): RedirectResponse
    {
        $this->exports->review($request, $batch);

        return back()->with('success', 'Accounting export reviewed.');
    }

    public function download(AccountingExport $batch): Response
    {
        Gate::authorize('review-financial');

        return response($this->exports->csv($batch), 200, ['Content-Type' => 'text/csv', 'Content-Disposition' => 'attachment; filename="sedona-accounting-'.$batch->id.'.csv"']);
    }

    public function delivered(Request $request, AccountingExport $batch): RedirectResponse
    {
        $data = $request->validate(['reference' => 'required|string|max:255']);
        $this->exports->deliver($request, $batch, $data['reference']);

        return back()->with('success', 'Manual delivery acknowledgment recorded.');
    }

    public function handover(Request $request, Shift $shift, FinancialPostingService $posting, ApprovalService $approvals): RedirectResponse
    {
        abort_unless(config('sedona.shift_handover_enabled'), 404);
        $data = $request->validate(['accepted_cash' => 'required|numeric|min:0|max:50000000|decimal:0,2', 'notes' => 'required|string|max:1000']);
        $result = $posting->execute($request, 'shift.accept', $shift->id, $data, function () use ($request, $shift, $data, $approvals): array {
            $shift = Shift::whereKey($shift->id)->lockForUpdate()->firstOrFail();
            abort_unless($request->user()->can('manage_shifts') && $request->user()->is_active, 403);
            abort_if($shift->opened_by === $request->user()->id || ($shift->incoming_user_id && $shift->incoming_user_id !== $request->user()->id), 403, 'The designated incoming operator must count this handover.');
            if (! $shift->closed_at || ! in_array($shift->handover_status, ['pending', 'variance_approved'], true) || Shift::whereNull('closed_at')->lockForUpdate()->exists()) {
                throw ValidationException::withMessages(['shift' => 'Handover is not available or another drawer is open.']);
            }
            $this->days->assertWritable();
            $amount = BillingService::cents($data['accepted_cash']);
            $variance = $amount - BillingService::cents($shift->cash_total);
            if ($shift->handover_status === 'variance_approved' && $amount !== BillingService::cents($shift->accepted_cash)) {
                throw ValidationException::withMessages(['accepted_cash' => 'Accept the independently reviewed count.']);
            }
            if ($variance !== 0 && $shift->handover_status === 'pending') {
                $shift->update(['incoming_user_id' => $request->user()->id, 'accepted_cash' => $amount / 100, 'handover_variance' => $variance / 100, 'handover_status' => 'disputed']);
                $approvals->request($request, 'handover_variance', $shift->id, [], $data['notes']);
                $this->audit->record($request, 'shift.handover_disputed', $shift, ['counted_cents' => $amount, 'variance_cents' => $variance, 'notes' => $data['notes']]);

                return ['disputed' => true];
            }
            $shift->update(['accepted_by' => $request->user()->id, 'accepted_at' => now(), 'accepted_cash' => $amount / 100, 'handover_variance' => $variance / 100, 'handover_status' => 'accepted']);
            $next = Shift::create(['opened_by' => $request->user()->id, 'shift_type' => Shift::typeForTime(now()), 'shift_date' => $this->days->date(), 'opened_at' => now(), 'opening_float' => $amount / 100, 'expected_cash' => $amount / 100, 'cash_total' => $amount / 100, 'handover_from_id' => $shift->id, 'is_frozen' => false]);
            $this->audit->record($request, 'shift.handover_accepted', $shift, ['incoming_user_id' => $request->user()->id, 'accepted_cents' => $amount, 'new_shift_id' => $next->id, 'notes' => $data['notes']]);

            return ['shift_id' => $next->id, 'disputed' => false];
        });

        return back()->with('success', $result['disputed'] ? 'Count disagreement recorded for independent review.' : 'Handover accepted; your new drawer is open.');
    }

    public function issueFolio(Request $request, Folio $folio, InvoiceService $invoices): RedirectResponse
    {
        return redirect()->route('invoices.show', $invoices->issue($request, $folio));
    }

    public function issueOrder(Request $request, Order $order, InvoiceService $invoices): RedirectResponse
    {
        return redirect()->route('invoices.show', $invoices->issue($request, $order));
    }

    public function invoice(Request $request, Invoice $invoice, InvoiceService $invoices): View
    {
        $invoices->authorizeInvoice($request, $invoice);

        return view('accounting.invoice', compact('invoice'));
    }

    public function printInvoice(Request $request, Invoice $invoice, FinancialPostingService $posting, InvoiceService $invoices): View
    {
        $invoices->authorizeInvoice($request, $invoice);
        $result = $posting->execute($request, 'invoice.print', $invoice->id, [], function () use ($request, $invoice): array {
            Invoice::whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $copy = DB::table('audit_logs')->where('event', 'invoice.print_requested')->where('auditable_type', Invoice::class)->where('auditable_id', $invoice->id)->count() + 1;
            $this->audit->record($request, 'invoice.print_requested', $invoice, ['copy_number' => $copy]);

            return ['copy_number' => $copy];
        });

        return view('accounting.invoice', ['invoice' => $invoice, 'copy' => $result['copy_number'], 'printRequested' => true]);
    }
}
