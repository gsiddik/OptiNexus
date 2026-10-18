<?php

namespace App\Jobs;

use App\Mail\UserInvitationMail;
use App\Models\UserInvitation;
use App\Services\AuditService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one invitation e-mail. The raw token travels in the job payload, which is encrypted (ShouldBeEncrypted) so it is not
 * readable in the queue table; it is never stored anywhere else. A job whose invitation was resent, revoked, accepted or has expired
 * in the meantime does nothing: the link it carries is no longer the live one.
 */
class SendUserInvitation implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public const MAX_ATTEMPTS = 3;

    public function __construct(public readonly string $invitationId, public readonly string $token) {}

    public function handle(AuditService $audit): void
    {
        $invitation = UserInvitation::query()->with(['application', 'tenant', 'inviter'])->find($this->invitationId);

        if (! $invitation || ! $invitation->isOpen() || ! hash_equals($invitation->token_hash, UserInvitation::hashToken($this->token))) {
            return;
        }

        $link = rtrim((string) config('app.url'), '/').'/invitations/'.$this->token;

        try {
            Mail::to($invitation->email)->send(new UserInvitationMail($invitation, $link));
        } catch (Throwable $e) {
            report($e);

            if ($this->job !== null && ! $this->job instanceof SyncJob && $this->attempts() < self::MAX_ATTEMPTS) {
                $this->release(60 * $this->attempts());

                return;
            }

            $this->markFailed($invitation, $audit);

            return;
        }

        $invitation->forceFill([
            'delivery_status' => UserInvitation::DELIVERY_SENT,
            'delivery_error_code' => null,
            'delivery_attempted_at' => now(),
            'last_sent_at' => now(),
        ])->save();

        $audit->record('invitation.sent', null, tenantId: $invitation->tenant_id, applicationId: $invitation->application_id,
            resourceType: 'UserInvitation', resourceId: $invitation->id, actorIdentity: 'system', source: 'cgo',
            metadata: ['mailer' => config('mail.default')]);
    }

    private function markFailed(UserInvitation $invitation, AuditService $audit): void
    {
        $invitation->forceFill([
            'delivery_status' => UserInvitation::DELIVERY_FAILED,
            'delivery_error_code' => 'MAIL_TRANSPORT_FAILED',
            'delivery_attempted_at' => now(),
        ])->save();

        $audit->record('invitation.send_failed', null, tenantId: $invitation->tenant_id, applicationId: $invitation->application_id,
            resourceType: 'UserInvitation', resourceId: $invitation->id, actorIdentity: 'system', source: 'cgo',
            metadata: ['mailer' => config('mail.default'), 'error_code' => 'MAIL_TRANSPORT_FAILED']);
    }

    public function failed(Throwable $e): void
    {
        $invitation = UserInvitation::query()->find($this->invitationId);

        if ($invitation && $invitation->delivery_status === UserInvitation::DELIVERY_QUEUED) {
            $this->markFailed($invitation, app(AuditService::class));
        }
    }
}
