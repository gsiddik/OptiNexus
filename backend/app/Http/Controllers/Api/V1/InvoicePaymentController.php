<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\InvoicePaymentResource;
use App\Models\InvoicePayment;
use App\Services\AuditService;
use App\Services\Commercial\SubmittedPaymentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class InvoicePaymentController extends Controller
{
    use ApiResponses;

    public function show(InvoicePayment $payment)
    {
        return $this->ok(new InvoicePaymentResource($payment));
    }

    public function verify(Request $request, InvoicePayment $payment, SubmittedPaymentService $payments)
    {
        return $this->review($request, $payment, $payments, true);
    }

    public function reject(Request $request, InvoicePayment $payment, SubmittedPaymentService $payments)
    {
        return $this->review($request, $payment, $payments, false);
    }

    private function review(Request $request, InvoicePayment $payment, SubmittedPaymentService $payments, bool $verify)
    {
        $data = $request->validate(['note' => ['nullable', 'string', 'max:5000']]);
        $payment = $payments->review($payment, $verify, $request->user()->id, $data['note'] ?? null);
        app(AuditService::class)->record($verify ? 'invoice.payment_verified' : 'invoice.payment_rejected', $request,
            tenantId: $payment->tenant_id, resourceType: 'InvoicePayment', resourceId: $payment->id);

        return $this->ok(new InvoicePaymentResource($payment));
    }

    public function proof(Request $request, InvoicePayment $payment)
    {
        abort_unless($payment->proof, 404);
        $proof = $payment->proof;
        $headers = ['Content-Type' => $proof['mime_type'], 'X-Content-Type-Options' => 'nosniff'];

        return $request->boolean('download')
            ? Storage::disk('local')->download($proof['path'], $proof['original_filename'], $headers)
            : Storage::disk('local')->response($proof['path'], $proof['original_filename'], $headers);
    }
}
