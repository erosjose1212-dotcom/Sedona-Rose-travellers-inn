<?php

namespace App\Services;

use App\Models\Folio;
use App\Models\Invoice;
use App\Models\Order;
use App\Models\PaymentEntry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class InvoiceService
{
    public function __construct(private BusinessDayService $days, private AuditService $audit, private FinancialPostingService $posting) {}

    public function issue(Request $request, Folio|Order $source): Invoice
    {
        Gate::authorize('record_payments');
        if ($source instanceof Order) {
            $this->authorizeOrder($request, $source);
        }
        $column = $source instanceof Folio ? 'folio_id' : 'order_id';
        $result = $this->posting->execute($request, 'invoice.issue.'.$column, $source->id, [], function () use ($request, $source, $column): array {
            $source = $source::whereKey($source->id)->lockForUpdate()->firstOrFail();
            $existing = Invoice::where($column, $source->id)->first();
            if ($existing) {
                return ['invoice_id' => $existing->id];
            }
            abort_if($source instanceof Folio ? $source->status !== 'checked_out' || $source->force_checkout : $source->type !== 'walkin_pos' || $source->status === 'cancelled', 409, 'Invoices require a completed paid sale.');
            $settingsRow = DB::table('accounting_settings')->where('id', 1)->lockForUpdate()->first();
            $settings = json_decode($settingsRow->configuration, true, 512, JSON_THROW_ON_ERROR);
            $this->requireConfiguration($settings);
            $at = $source instanceof Folio ? $source->checked_out_at : $source->created_at;
            $date = $this->days->date($at);
            $this->days->assertWritable($date);
            $gross = BillingService::cents($source instanceof Folio ? $source->gross_total : $source->total);
            $discount = $source instanceof Folio ? BillingService::cents($source->discount_amount) : 0;
            $net = $gross - $discount;
            $rate = $settings['tax_mode'] === 'vat_inclusive' ? (int) $settings['tax_rate_bps'] : 0;
            $taxable = $net;
            $taxApproval = null;
            if ($source instanceof Folio && $rate > 0 && in_array(strtoupper($source->discount_type ?? ''), ['SENIOR', 'PWD'], true)) {
                $taxApproval = app(ApprovalService::class)->invoiceTaxApproval($source);
                $taxable = BillingService::cents($taxApproval->payload['taxable_amount']);
            }
            $tax = $rate > 0 ? (int) round($taxable * $rate / (10000 + $rate)) : 0;
            $number = $settings['invoice_series'].'-'.str_pad((string) $settings['next_sequence'], 8, '0', STR_PAD_LEFT);
            $invoice = Invoice::create([$column => $source->id, 'number' => $number, 'business_date' => $date, 'user_id' => $request->user()->id, 'document' => [
                'issuer' => array_intersect_key($settings, array_flip(['legal_name', 'address', 'tin', 'registration', 'approval_reference', 'tax_mode', 'tax_rate_bps'])),
                'source_type' => $source::class, 'source_id' => $source->id, 'transaction_id' => $source->transaction_id,
                'customer' => $source instanceof Folio ? $source->guest?->name : ($source->customer_name ?? 'Walk-in guest'),
                'gross_cents' => $gross, 'discount_cents' => $discount, 'net_cents' => $net, 'tax_cents' => $tax, 'tax_base_cents' => $net - $tax,
                'taxable_cents' => $taxable, 'exempt_cents' => $net - $taxable, 'tax_approval_id' => $taxApproval?->id,
                'deposit_applied_cents' => $source instanceof Folio ? BillingService::cents(app(BillingService::class)->calculate($source)['depositApplied']) : 0,
                'payment_entries' => DB::table('payment_entries')->where($column, $source->id)->orderBy('id')->get()->toArray(), 'issued_at' => now()->toIso8601String(),
            ]]);
            $settings['next_sequence']++;
            DB::table('accounting_settings')->where('id', 1)->update(['configuration' => json_encode($settings, JSON_THROW_ON_ERROR), 'updated_at' => now()]);
            $this->audit->record($request, 'invoice.issued', $invoice, ['number' => $number, 'source_id' => $source->id, 'net_cents' => $net, 'tax_cents' => $tax]);

            return ['invoice_id' => $invoice->id];
        });

        return Invoice::findOrFail($result['invoice_id']);
    }

    public function authorizeInvoice(Request $request, Invoice $invoice): void
    {
        Gate::authorize('record_payments');
        if ($invoice->order_id) {
            $this->authorizeOrder($request, Order::findOrFail($invoice->order_id));
        }
    }

    private function authorizeOrder(Request $request, Order $order): void
    {
        abort_unless((int) $order->user_id === $request->user()->id || $request->user()->can('review-financial'), 403);
    }

    public static function refundTaxCents(PaymentEntry $entry): int
    {
        if ($entry->kind !== 'pos_refund' || ! $entry->reversal_of_id) {
            return 0;
        }
        $invoice = Invoice::where('order_id', $entry->order_id)->first();
        if (! $invoice || $invoice->document['net_cents'] <= 0) {
            return 0;
        }
        $previous = PaymentEntry::where('reversal_of_id', $entry->reversal_of_id)->where('id', '<', $entry->id)->get()->sum(fn ($payment): int => -BillingService::cents($payment->cash_amount) - BillingService::cents($payment->gcash_amount));
        $amount = -BillingService::cents($entry->cash_amount) - BillingService::cents($entry->gcash_amount);
        $tax = $invoice->document['tax_cents'];
        $total = $invoice->document['net_cents'];

        return (int) round(($previous + $amount) * $tax / $total) - (int) round($previous * $tax / $total);
    }

    /** @param array<string, mixed> $settings */
    public function requireConfiguration(array $settings): void
    {
        foreach (['legal_name', 'address', 'tin', 'registration', 'approval_reference', 'invoice_series', 'next_sequence'] as $field) {
            if (empty($settings[$field])) {
                throw ValidationException::withMessages(['configuration' => 'Accountant-approved invoice setup is incomplete: '.$field]);
            }
        }
        if ((int) $settings['next_sequence'] < 1 || (int) $settings['next_sequence'] > 99999999) {
            throw ValidationException::withMessages(['configuration' => 'The approved invoice number range has been exhausted.']);
        }
        if (! ($settings['invoice_enabled'] ?? false) || ! in_array($settings['tax_mode'], ['vat_inclusive', 'non_vat', 'exempt'], true) || ($settings['tax_mode'] === 'vat_inclusive' && empty($settings['tax_rate_bps']))) {
            throw ValidationException::withMessages(['configuration' => 'Enable invoices only after approving tax treatment and numbering.']);
        }
    }
}
