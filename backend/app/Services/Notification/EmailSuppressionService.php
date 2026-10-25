<?php

namespace App\Services\Notification;

use App\Models\EmailSuppression;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Keeps OptiNexus in step with the mail provider's suppression list (docs/OPERATIONAL_EMAIL.md). A hard bounce, a spam complaint
 * or a manual suppression stops further operational e-mail to that address; the dispatcher then cancels instead of sending, which
 * protects the sender reputation. Soft bounces are transient and change nothing. Replaying the same webhook is harmless.
 */
class EmailSuppressionService
{
    public function __construct(private readonly AuditService $audit) {}

    /** @return string what happened: suppressed, released, ignored */
    public function handlePostmark(array $payload): string
    {
        $type = (string) ($payload['RecordType'] ?? '');

        return match ($type) {
            'Bounce' => $this->postmarkBounce($payload),
            'SpamComplaint' => $this->suppress((string) ($payload['Email'] ?? ''), EmailSuppression::REASON_SPAM_COMPLAINT,
                $payload['MessageID'] ?? null, $payload['Description'] ?? 'Spam complaint'),
            'SubscriptionChange' => $this->postmarkSubscriptionChange($payload),
            default => 'ignored',
        };
    }

    public function release(EmailSuppression $suppression, User $actor): ?string
    {
        if (! $suppression->isActive()) {
            return 'EMAIL_SUPPRESSION_NOT_ACTIVE';
        }
        $suppression->update(['released_at' => now(), 'released_by' => $actor->id]);
        $this->audit->record('email_suppression.released', null, $actor, resourceType: 'EmailSuppression', resourceId: $suppression->id,
            oldValue: ['reason' => $suppression->reason], newValue: ['email' => $suppression->email]);

        return null;
    }

    private function postmarkBounce(array $payload): string
    {
        // Postmark deactivates an address on hard bounces (and a few other types); Inactive says so for any bounce type.
        $hard = ($payload['Type'] ?? null) === 'HardBounce' || ($payload['Inactive'] ?? false) === true;
        if (! $hard) {
            return 'ignored';
        }

        return $this->suppress((string) ($payload['Email'] ?? ''), EmailSuppression::REASON_HARD_BOUNCE,
            $payload['MessageID'] ?? null, $payload['Description'] ?? ($payload['Details'] ?? 'Hard bounce'));
    }

    private function postmarkSubscriptionChange(array $payload): string
    {
        $email = (string) ($payload['Recipient'] ?? '');
        if (($payload['SuppressSending'] ?? null) === true) {
            $reason = match ($payload['SuppressionReason'] ?? null) {
                'HardBounce' => EmailSuppression::REASON_HARD_BOUNCE,
                'SpamComplaint' => EmailSuppression::REASON_SPAM_COMPLAINT,
                default => EmailSuppression::REASON_MANUAL,
            };

            return $this->suppress($email, $reason, $payload['MessageID'] ?? null, 'Suppressed by the mail provider');
        }
        // Reactivated in Postmark: lift our copy too.
        $suppression = EmailSuppression::query()->active()->where('email', $this->normalize($email))->first();
        if (! $suppression) {
            return 'ignored';
        }
        $suppression->update(['released_at' => now(), 'released_by' => null]);
        $this->audit->record('email_suppression.released', null, actorIdentity: 'postmark', resourceType: 'EmailSuppression',
            resourceId: $suppression->id, newValue: ['email' => $suppression->email]);

        return 'released';
    }

    private function suppress(string $email, string $reason, mixed $messageId, mixed $description): string
    {
        $email = $this->normalize($email);
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'ignored';
        }

        try {
            return $this->store($email, $reason, $messageId, $description);
        } catch (UniqueConstraintViolationException) {
            return 'suppressed'; // a concurrent delivery of the same report created the row first
        }
    }

    private function store(string $email, string $reason, mixed $messageId, mixed $description): string
    {
        return DB::transaction(function () use ($email, $reason, $messageId, $description) {
            $row = EmailSuppression::query()->lockForUpdate()->where('email', $email)->first();
            if ($row?->isActive()) {
                return 'suppressed';
            }
            $values = [
                'reason' => $reason, 'provider' => 'postmark', 'suppressed_at' => now(), 'released_at' => null, 'released_by' => null,
                'provider_message_id' => is_scalar($messageId) ? Str::limit((string) $messageId, 250, '') : null,
                'description' => is_scalar($description) ? Str::limit((string) $description, 500, '') : null,
            ];
            if ($row) {
                $row->update($values);
            } else {
                $row = EmailSuppression::create(['email' => $email] + $values);
            }
            $this->audit->record('email_suppression.created', null, actorIdentity: 'postmark', resourceType: 'EmailSuppression',
                resourceId: $row->id, newValue: ['email' => $email, 'reason' => $reason]);

            return 'suppressed';
        });
    }

    private function normalize(string $email): string
    {
        return mb_strtolower(trim($email));
    }
}
