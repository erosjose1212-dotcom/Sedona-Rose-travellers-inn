@php
    $statusLabels = ['pending' => 'Needs review', 'approved' => 'Ready to apply', 'executed' => 'Applied', 'rejected' => 'Rejected', 'cancelled' => 'Cancelled'];
    $stockNames = $items->pluck('name', 'id')->all();
@endphp
<section id="approvalQueue" class="hms-card">
    <h2 class="font-bold text-lg">{{ auth()->user()->can('review-financial') ? 'Property review queue' : 'Your requests and outcomes' }}</h2>
    <p class="text-sm text-slate-600 mt-1">Review the requested change, approve or reject it, then apply approved actions. Paid expense reviews are completed when reviewed.</p>
    <nav aria-label="Request status" class="grid grid-cols-2 lg:grid-cols-4 gap-3 my-4">
        @foreach(['pending' => 'Needs review', 'approved' => 'Ready to apply', 'history' => 'History', 'all' => 'All requests'] as $status => $label)
            <a href="{{ route('approvals.index', array_merge(request()->query(), ['status' => $status, 'page' => 1])) }}" @if($statusFilter === $status) aria-current="page" @endif
               @class(['rounded-lg border p-3 flex items-center justify-between gap-2 text-sm font-semibold', 'bg-rose-50 border-rose-200 text-brand' => $statusFilter === $status, 'bg-slate-50 border-slate-200 text-slate-600' => $statusFilter !== $status])>
                <span>{{ $label }}</span><span class="font-bold text-lg">{{ $statusCounts[$status] }}</span>
            </a>
        @endforeach
    </nav>
    <p class="text-xs text-slate-500 mb-3">{{ in_array($statusFilter, ['pending', 'approved']) ? 'Oldest requests first.' : 'Newest requests first.' }} Owners can approve their own room-rate and custom-discount changes. Other requests require a different admin or owner.</p>
    <div class="space-y-3">
    @forelse($requests as $approval)
        <article class="rounded-lg border border-slate-200 bg-white p-4" aria-label="Request {{ $approval->id }}">
            <div class="flex flex-wrap items-start justify-between gap-3 mb-3">
                <div>
                    <h3 class="font-bold">{{ $approval->kindLabel() }} <span class="text-slate-500 font-normal">#{{ $approval->id }}</span></h3>
                    <p class="text-sm mt-1">{{ $approval->sourceLabel() }}</p>
                    <p class="text-xs text-slate-500 mt-1">Requested by {{ $approval->requester?->name ?? 'Staff #'.$approval->requested_by }} · {{ $approval->created_at->format('m/d/Y h:i A') }} · {{ $approval->created_at->diffForHumans() }}</p>
                </div>
                <span @class(['px-3 py-1 rounded-full text-xs font-semibold', 'bg-amber-50 text-amber-800' => $approval->status === 'pending', 'bg-emerald-50 text-emerald-800' => $approval->status === 'approved', 'bg-sky-50 text-sky-800' => $approval->status === 'executed', 'bg-slate-100 text-slate-600' => in_array($approval->status, ['rejected', 'cancelled'])])>{{ $approval->isStockChange() && $approval->status === 'pending' ? 'Apply directly' : ($statusLabels[$approval->status] ?? ucfirst($approval->status)) }}</span>
            </div>
            <div class="grid gap-4 lg:grid-cols-3">
                <div class="lg:col-span-2 space-y-3">
                    <p class="text-sm"><strong>Reason:</strong> {{ $approval->reason }}</p>
                    <dl class="grid grid-cols-1 sm:grid-cols-2 gap-2 text-sm rounded-lg bg-slate-50 p-3">
                        @foreach($approval->changeDetails($stockNames) as $label => $value)
                            <div><dt class="text-xs text-slate-500">{{ $label }}</dt><dd class="font-semibold break-words">{{ $value }}</dd></div>
                        @endforeach
                    </dl>
                    @if($approval->evidence_reference)<p class="text-sm"><strong>Evidence:</strong> {{ $approval->evidence_reference }}</p>@endif
                    @if($approval->reviewed_by)<p class="text-xs text-slate-600">Reviewed by {{ $approval->reviewer?->name ?? 'Staff #'.$approval->reviewed_by }} · {{ $approval->reviewed_at?->format('m/d/Y h:i A') }}<br>{{ $approval->review_note }}</p>@endif
                    @if($approval->executed_at)<p class="text-xs text-slate-600">Applied by {{ $approval->executor?->name ?? 'Staff #'.$approval->executed_by }} · {{ $approval->executed_at->format('m/d/Y h:i A') }}</p>@endif
                    <details class="text-xs text-slate-500"><summary class="cursor-pointer">Technical record</summary><p class="mt-2">{{ class_basename($approval->source_type) }} #{{ $approval->source_id }}</p><pre class="whitespace-pre-wrap break-words mt-1">{{ json_encode($approval->payload, JSON_PRETTY_PRINT) }}</pre></details>
                </div>
                <div class="space-y-3">
                    @if($approval->kind === 'folio_override' && array_key_exists('checked_in_at', $approval->payload))
                        <p class="text-sm text-slate-600">This older request cannot be applied. Cancel it and ask RA to correct the time in Check-in Time Corrections.</p>
                    @elseif($approval->kind === 'handover_variance' && !config('sedona.shift_handover_enabled'))
                        <p class="text-sm text-slate-600">Handover is disabled. This request remains as history and does not block day closing.</p>
                    @elseif($approval->isStockChange() && in_array($approval->status, ['pending', 'approved']) && !auth()->user()->hasAnyRole(['admin', 'owner']))
                        <p class="text-sm text-slate-600">Only an admin or owner can apply this older stock adjustment. Add new deliveries from Stock.</p>
                    @elseif($approval->isStockChange() && in_array($approval->status, ['pending', 'approved']) && ($approval->requested_by === auth()->id() || auth()->user()->can('review-financial')))
                        <form method="POST" action="{{ route('approvals.execute', $approval) }}" class="grid gap-2">
                            @csrf<input name="operation_key" type="hidden" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            <button class="btn-occupied">Apply stock update now</button>
                            <p class="text-xs text-slate-500">No approval is required. New stock adjustments and recounts are saved immediately from Stock.</p>
                        </form>
                    @elseif($approval->status === 'pending' && auth()->user()->can('review-financial') && ($approval->requested_by !== auth()->id() || $approval->permitsOwnerSelfReview(auth()->user())))
                        <form method="POST" action="{{ route('approvals.review', $approval) }}" class="grid gap-2">
                            @csrf
                            <label for="reviewNote{{ $approval->id }}" class="text-sm font-semibold">Review note</label>
                            <textarea id="reviewNote{{ $approval->id }}" name="note" required maxlength="1000" placeholder="What did you verify?" class="form-control-hms"></textarea>
                            <button name="decision" value="approve" class="btn-occupied">{{ $approval->kind === 'expense_review' ? 'Approve expense review' : 'Approve request' }}</button>
                            <button name="decision" value="reject" class="border border-rose-200 bg-rose-50 text-rose-800 rounded-lg px-3 py-2 text-sm font-semibold">Reject request</button>
                            <p class="text-xs text-slate-500">{{ $approval->kind === 'expense_review' ? 'This expense was already paid. Approval completes its review.' : 'Approval records the decision. Apply the change from Ready to apply.' }}</p>
                        </form>
                    @elseif($approval->status === 'approved' && ($approval->requested_by === auth()->id() || auth()->user()->can('review-financial')))
                        <form method="POST" action="{{ route('approvals.execute', $approval) }}" class="grid gap-2">
                            @csrf<input name="operation_key" type="hidden" value="{{ (string) \Illuminate\Support\Str::uuid() }}">
                            @if(auth()->user()->can('review-financial') && in_array($approval->kind, ['deposit_refund', 'pos_refund', 'expense_reversal', 'cash_drop', 'float_addition', 'bank_deposit']))
                                <label class="text-xs text-slate-600">Reason if covering another drawer<input name="shift_override_reason" maxlength="500" class="form-control-hms"></label>
                            @endif
                            <button class="btn-occupied">Apply approved action</button>
                            <p class="text-xs text-slate-500">Applies these approved details once and records the result in History.</p>
                        </form>
                    @else
                        <p class="text-sm text-slate-600">{{ $approval->status === 'pending' ? 'A different admin or owner must review this request.' : 'Completed record. No further action is needed.' }}</p>
                    @endif
                    @if(in_array($approval->status, ['pending', 'approved']) && $approval->kind !== 'expense_review' && ($approval->requested_by === auth()->id() || auth()->user()->can('review-financial')))
                        <details class="text-sm"><summary class="cursor-pointer text-slate-500">Cancel this request</summary>
                            <form method="POST" action="{{ route('approvals.cancel', $approval) }}" class="grid gap-2 mt-2">@csrf<label class="text-xs">Cancellation reason<input name="reason" required maxlength="1000" class="form-control-hms"></label><button class="text-rose-700 font-semibold">Cancel unexecuted request</button></form>
                        </details>
                    @endif
                </div>
            </div>
        </article>
    @empty
        <div class="rounded-lg bg-slate-50 p-6 text-center"><p class="font-semibold">{{ match($statusFilter) { 'pending' => 'No requests waiting for review.', 'approved' => 'No approved actions waiting to be applied.', 'history' => 'No completed requests yet.', default => 'No recorded requests.' } }}</p><p class="text-sm text-slate-500 mt-1">Choose another status above or create a new request.</p></div>
    @endforelse
    </div>
    <div class="mt-4">{{ $requests->links() }}</div>
</section>
