<?php

namespace App\Services\Commercial;

use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SubmittedPaymentService
{
    public function submit(Invoice $invoice, array $data, string $actor): InvoicePayment
    {
        return DB::transaction(function () use ($invoice, $data, $actor) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($invoice->id);
            $fingerprint = hash('sha256', json_encode($data, JSON_THROW_ON_ERROR));
            $existing = InvoicePayment::query()->where('submission_key', $data['submission_key'])->first();
            if ($existing) {
                abort_unless($existing->invoice_id === $invoice->id && $existing->recorded_by === $actor && ($existing->metadata['request_hash'] ?? null) === $fingerprint, 409);

                return $existing;
            }
            if (! in_array($invoice->status, ['ISSUED', 'OVERDUE'], true)
                || Money::compare((string) $data['amount'], $invoice->balance_due) !== 0
                || Money::compare($invoice->balance_due, '0.00') <= 0
                || $invoice->payments()->where('status', 'SUBMITTED')->exists()) {
                throw ValidationException::withMessages(['invoice_id' => ['Submit exactly the outstanding amount of an issued invoice with no pending payment.']]);
            }
            $proof = isset($data['proof']) ? $this->saveProof($data['proof']) : null;
            try {
                return InvoicePayment::create([
                    'invoice_id' => $invoice->id, 'amount' => $data['amount'], 'paid_at' => $data['payment_date'],
                    'method' => $data['payment_method'], 'reference' => $data['transaction_reference'] ?? null,
                    'bank_name' => $data['bank_name'] ?? null, 'account_name' => $data['account_name'] ?? null,
                    'note' => $data['note'] ?? null, 'recorded_by' => $actor, 'status' => 'SUBMITTED',
                    'submission_key' => $data['submission_key'], 'proof' => $proof, 'metadata' => ['request_hash' => $fingerprint],
                ]);
            } catch (\Throwable $e) {
                if ($proof) {
                    Storage::disk('local')->delete($proof['path']);
                }
                throw $e;
            }
        });
    }

    public function review(InvoicePayment $payment, bool $verify, string $actor, ?string $note): InvoicePayment
    {
        return DB::transaction(function () use ($payment, $verify, $actor, $note) {
            $invoice = Invoice::query()->lockForUpdate()->findOrFail($payment->invoice_id);
            $payment = InvoicePayment::query()->lockForUpdate()->findOrFail($payment->id);
            abort_unless($payment->status === 'SUBMITTED', 409, 'Only submitted payments may be reviewed.');
            if ($verify) {
                abort_unless(in_array($invoice->status, ['ISSUED', 'OVERDUE'], true)
                    && Money::compare($payment->amount, $invoice->balance_due) === 0, 409, 'Invoice balance changed.');
                $invoice->update(['balance_due' => '0.00', 'status' => 'PAID', 'paid_at' => now()]);
            }
            $payment->update(['status' => $verify ? 'VERIFIED' : 'REJECTED', 'reviewed_by' => $actor,
                'verified_at' => $verify ? now() : null, 'verification_note' => $note]);

            return $payment;
        });
    }

    public function attachProof(InvoicePayment $payment, array $data): InvoicePayment
    {
        return DB::transaction(function () use ($payment, $data) {
            $payment = InvoicePayment::query()->lockForUpdate()->findOrFail($payment->id);
            abort_unless($payment->status === 'SUBMITTED', 409);
            $old = $payment->proof;
            $proof = $this->saveProof($data);
            try {
                $payment->update(['proof' => $proof]);
            } catch (\Throwable $e) {
                Storage::disk('local')->delete($proof['path']);
                throw $e;
            }
            if ($old) {
                DB::afterCommit(fn () => Storage::disk('local')->delete($old['path']));
            }

            return $payment;
        });
    }

    private function saveProof(array $data): array
    {
        $bytes = base64_decode($data['content'], true);
        $mime = $bytes === false ? '' : (new \finfo(FILEINFO_MIME_TYPE))->buffer($bytes);
        if ($bytes === false || strlen($bytes) > 5 * 1024 * 1024 || ! in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'application/pdf'], true)) {
            throw ValidationException::withMessages(['file' => ['Proof must be JPG, PNG, WEBP or PDF, up to 5 MB.']]);
        }
        $path = 'commercial-payment-proofs/'.Str::uuid();
        if (! Storage::disk('local')->put($path, $bytes)) {
            throw new \RuntimeException('Could not save proof.');
        }

        return ['id' => (string) Str::uuid(), 'path' => $path, 'original_filename' => basename($data['filename']), 'mime_type' => $mime, 'size' => strlen($bytes)];
    }
}
