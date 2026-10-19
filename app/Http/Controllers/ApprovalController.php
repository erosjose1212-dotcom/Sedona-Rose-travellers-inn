<?php

namespace App\Http\Controllers;

use App\Models\ApprovalRequest;
use App\Models\Folio;
use App\Models\PaymentEntry;
use App\Models\PosItem;
use App\Models\Shift;
use App\Models\StatementLine;
use App\Services\ApprovalService;
use App\Services\FinancialPostingService;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ApprovalController extends Controller
{
    public function __construct(private ApprovalService $approvals, private FinancialPostingService $posting) {}

    public function index(Request $request): View
    {
        $query = ApprovalRequest::query();
        if (! $request->user()->can('review-financial')) {
            $query->where('requested_by', $request->user()->id);
        }
        $counts = (clone $query)->select('status')->selectRaw('COUNT(*) as total')->groupBy('status')->pluck('total', 'status')->all();
        $statusCounts = ['pending' => $counts['pending'] ?? 0, 'approved' => $counts['approved'] ?? 0,
            'history' => ($counts['executed'] ?? 0) + ($counts['rejected'] ?? 0) + ($counts['cancelled'] ?? 0), 'all' => array_sum($counts)];
        $statusFilter = $request->query('status', $request->user()->can('review-financial') ? 'pending' : 'all');
        if (! in_array($statusFilter, ['pending', 'approved', 'history', 'all'], true)) {
            $statusFilter = $request->user()->can('review-financial') ? 'pending' : 'all';
        }
        if ($statusFilter === 'history') {
            $query->whereIn('status', ['executed', 'rejected', 'cancelled']);
        } elseif ($statusFilter !== 'all') {
            $query->where('status', $statusFilter);
        }
        $query->with(['requester', 'reviewer', 'executor', 'source' => fn (MorphTo $source) => $source->morphWith([Folio::class => ['room', 'guest'], PaymentEntry::class => ['order.room']])]);
        if (in_array($statusFilter, ['pending', 'approved'], true)) {
            $query->oldest()->orderBy('id');
        } else {
            $query->latest()->orderByDesc('id');
        }

        $folios = Folio::with(['guest', 'room'])->latest()->limit(100)->get();
        $selectedFolio = $folios->firstWhere('id', $request->integer('folio_id'));
        if ($selectedFolio && (! $selectedFolio->isActive() || $selectedFolio->room->is_staff_quarters)) {
            $selectedFolio = null;
        }
        $discountType = $request->query('discount_type');
        $selectedDiscountType = is_string($discountType) && in_array(strtoupper($discountType), ['DC', 'SENIOR', 'PWD', 'NONE'], true)
            ? strtoupper($discountType) : null;
        $paymentsQuery = PaymentEntry::where('kind', 'pos_sale')->when(! $request->user()->can('review-financial'), fn ($q) => $q->where('user_id', $request->user()->id));

        return view('accounting.approvals', [
            'requests' => $query->paginate(20)->withQueryString(),
            'statusCounts' => $statusCounts,
            'statusFilter' => $statusFilter,
            'folios' => $folios,
            'selectedFolio' => $selectedFolio,
            'selectedDiscountType' => $selectedFolio ? $selectedDiscountType : null,
            'payments' => (clone $paymentsQuery)->latest()->limit(100)->get(),
            'invoiceFolios' => $request->user()->can('record_payments') ? Folio::with(['guest', 'room'])->where('status', 'checked_out')->where('force_checkout', false)->latest('id')->paginate(10, ['*'], 'invoice_folio_page')->withQueryString() : null,
            'invoiceSales' => $request->user()->can('record_payments') ? (clone $paymentsQuery)->with('order')->latest('id')->paginate(10, ['*'], 'invoice_sale_page')->withQueryString() : null,
            'statements' => $request->user()->can('review-financial') ? StatementLine::latest()->limit(100)->get() : collect(),
            'items' => PosItem::notRetired()->orderBy('name')->get(),
            'shift' => Shift::whereNull('closed_at')->first(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['kind' => 'required|string', 'source_id' => 'required|integer|min:1', 'payload' => 'nullable|array', 'reason' => 'required|string|max:1000', 'evidence_reference' => 'nullable|string|max:255']);
        $result = $this->posting->execute($request, 'approval.request', $data['source_id'], $data, function () use ($request, $data): array {
            $approval = $this->approvals->request($request, $data['kind'], $data['source_id'], array_filter($data['payload'] ?? [], fn ($value): bool => $value !== null), $data['reason'], $data['evidence_reference'] ?? null);

            return ['approval_id' => $approval->id, 'status' => $approval->status];
        });

        if (($result['status'] ?? 'pending') === 'executed') {
            return redirect()->route('approvals.index', ['status' => 'history'])->with('success', 'Stock update applied immediately. No approval is required.');
        }
        if (($result['status'] ?? 'pending') === 'approved') {
            return redirect()->route('approvals.index', ['status' => 'approved'])->with('success', 'Request #'.$result['approval_id'].' approved by the owner. Apply the approved action to update the guest bill.');
        }

        return redirect()->route('approvals.index')->with('success', 'Request #'.$result['approval_id'].' awaits independent review.');
    }

    public function review(Request $request, ApprovalRequest $approval): RedirectResponse
    {
        $data = $request->validate(['decision' => 'required|in:approve,reject', 'note' => 'required|string|max:1000']);
        $this->approvals->review($request, $approval, $data['decision'] === 'approve', $data['note']);

        $approved = $approval->fresh()->status === 'approved';

        return redirect()->route('approvals.index', ['status' => $approved ? 'approved' : 'history'])
            ->with('success', $approved ? 'Request #'.$approval->id.' approved. Apply the recorded change below.' : 'Request #'.$approval->id.' review recorded in History.');
    }

    public function execute(Request $request, ApprovalRequest $approval): RedirectResponse
    {
        $request->validate(['shift_override_reason' => 'nullable|string|max:500']);
        $this->approvals->execute($request, $approval);

        return back()->with('success', 'Request #'.$approval->id.' applied and recorded in History.');
    }

    public function cancel(Request $request, ApprovalRequest $approval): RedirectResponse
    {
        $data = $request->validate(['reason' => 'required|string|max:1000']);
        $this->approvals->cancel($request, $approval, $data['reason']);

        return back()->with('success', 'Request cancelled with retained history.');
    }
}
