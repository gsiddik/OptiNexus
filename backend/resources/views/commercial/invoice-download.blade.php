<!doctype html>
<html lang="en"><head><meta charset="utf-8"><title>Invoice {{ $invoice->invoice_number }}</title>
<style>body{font:15px Arial,sans-serif;max-width:900px;margin:40px auto;color:#182438}table{width:100%;border-collapse:collapse}td,th{padding:10px;border-bottom:1px solid #ddd;text-align:left}dt{font-weight:bold}dd{margin:0 0 12px}footer{margin-top:30px;color:#555}</style></head><body>
<h1>Invoice {{ $invoice->invoice_number }}</h1><p>Status: {{ $invoice->status }}</p>
<dl><dt>Billing#</dt><dd>{{ $invoice->billing?->billing_number }}</dd><dt>Customer</dt><dd>{{ $invoice->customer?->business_name ?: $invoice->customer?->legal_name }}</dd>
<dt>Tenant</dt><dd>{{ $invoice->tenant?->name }}</dd><dt>Subscription#</dt><dd>{{ $invoice->subscription?->subscription_number }}</dd>
<dt>Issue Date</dt><dd>{{ ($invoice->issue_date ?? $invoice->issued_at)?->format('d/m/Y, H:i:s') ?? '—' }}</dd><dt>Due Date</dt><dd>{{ $invoice->due_date?->format('d/m/Y, H:i:s') }}</dd></dl>
<table><thead><tr><th>Description</th><th>Qty</th><th>Unit amount</th><th>Total</th></tr></thead><tbody>
@foreach($invoice->items as $item)<tr><td>{{ $item->description }}</td><td>{{ $item->quantity }}</td><td>{{ $item->unit_amount }}</td><td>{{ $item->total }}</td></tr>@endforeach
</tbody></table><p>Currency: {{ $invoice->currency }}</p><p>Subtotal: {{ $invoice->subtotal }}<br>Discount: {{ $invoice->discount_total }}<br>Tax: {{ $invoice->tax_total }}<br>Adjustment: {{ $invoice->adjustment_total }}</p><h2>Total: {{ $invoice->total }}</h2><p>Balance due: {{ $invoice->balance_due }}</p>
<footer>This document retains the original invoice number and issue date. You can print it or save it as PDF from your browser.</footer></body></html>
