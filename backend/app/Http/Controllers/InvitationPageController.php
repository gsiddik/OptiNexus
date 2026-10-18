<?php

namespace App\Http\Controllers;

use App\Models\OidcClient;
use App\Models\UserInvitation;
use App\Services\Delegation\InvitationException;
use App\Services\Delegation\InvitationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\View;

/**
 * The pages an invitee sees on Nexus (server-rendered, EN and ID). The e-mailed link only validates and hands the visitor to a
 * clean URL: nothing is consumed by opening it, so a mail scanner that fetches the link cannot burn the invitation.
 */
class InvitationPageController extends Controller
{
    private const SESSION_ID = 'invitation.id';

    private const SESSION_HASH = 'invitation.hash';

    private const SESSION_LANG = 'invitation.lang';

    public function __construct(private readonly InvitationService $invitations) {}

    public function open(Request $request, string $token): RedirectResponse|Response
    {
        $invitation = $this->invitations->findByToken($token);

        if (! $invitation) {
            return $this->stateResponse($request, InvitationException::INVALID, 404);
        }

        $request->session()->put([self::SESSION_ID => $invitation->id, self::SESSION_HASH => $invitation->token_hash]);
        $this->rememberLanguage($request);

        return redirect('/invitations/accept');
    }

    public function show(Request $request): Response
    {
        $this->rememberLanguage($request);
        $invitation = $this->fromSession($request);

        if (! $invitation) {
            return $this->stateResponse($request, InvitationException::INVALID, 404);
        }

        try {
            $this->invitations->assertOpen($invitation);
        } catch (InvitationException $e) {
            return $this->stateResponse($request, $e->state, $e->status, $invitation);
        }

        return $this->form($request, $invitation);
    }

    public function submit(Request $request): Response
    {
        $invitation = $this->fromSession($request);

        if (! $invitation) {
            return $this->stateResponse($request, InvitationException::INVALID, 404);
        }

        try {
            $this->invitations->assertOpen($invitation);
        } catch (InvitationException $e) {
            return $this->stateResponse($request, $e->state, $e->status, $invitation);
        }

        $existing = $this->invitations->needsExistingPassword($invitation);
        $errors = $this->validateInput($request, $existing);

        if ($errors !== []) {
            return $this->form($request, $invitation, $errors, 422);
        }

        try {
            $result = $this->invitations->accept($invitation->id, $invitation->token_hash, [
                'name' => $request->input('name'),
                'password' => $request->input('password'),
                'current_password' => $request->input('current_password'),
            ], $request);
        } catch (InvitationException $e) {
            if ($e->state === InvitationException::WRONG_PASSWORD) {
                $left = max(0, (int) config('invitations.confirm_max_attempts') - RateLimiter::attempts('invitation:confirm:'.$invitation->id));

                return $this->form($request, $invitation, ['current_password' => __('invitations.errors.wrong_password', ['left' => $left])], 422);
            }

            return $this->stateResponse($request, $e->state, $e->status, $invitation, $e->retryAfter);
        }

        $request->session()->forget([self::SESSION_ID, self::SESSION_HASH]);

        return $this->html($request, 'invitations.done', ['invitation' => $result['invitation'], 'continueUrl' => $this->continueUrl($invitation)]);
    }

    /** @return array<string, string> */
    private function validateInput(Request $request, bool $existing): array
    {
        $errors = [];
        $min = (int) config('invitations.password_min_length');

        if ($existing) {
            if ((string) $request->input('current_password') === '') {
                $errors['current_password'] = __('invitations.errors.current_password_required');
            }

            return $errors;
        }

        if (trim((string) $request->input('name')) === '' || mb_strlen((string) $request->input('name')) > 255) {
            $errors['name'] = __('invitations.errors.name_required');
        }

        $password = (string) $request->input('password');
        if ($password === '') {
            $errors['password'] = __('invitations.errors.password_required');
        } elseif (mb_strlen($password) < $min || mb_strlen($password) > 128) {
            $errors['password'] = __('invitations.errors.password_min', ['min' => $min]);
        } elseif (! hash_equals($password, (string) $request->input('password_confirmation'))) {
            $errors['password'] = __('invitations.errors.password_confirmed');
        }

        return $errors;
    }

    private function fromSession(Request $request): ?UserInvitation
    {
        $invitation = $this->invitations->findForSession(
            $request->session()->get(self::SESSION_ID),
            $request->session()->get(self::SESSION_HASH),
        );

        $this->applyLocale($request, $invitation);

        return $invitation;
    }

    private function rememberLanguage(Request $request): void
    {
        $lang = $request->query('lang');

        if (is_string($lang) && in_array($lang, config('invitations.locales'), true)) {
            $request->session()->put(self::SESSION_LANG, $lang);
        }
    }

    private function applyLocale(Request $request, ?UserInvitation $invitation): void
    {
        $lang = $request->session()->get(self::SESSION_LANG) ?? $invitation?->locale ?? 'en';
        app()->setLocale(in_array($lang, config('invitations.locales'), true) ? $lang : 'en');
    }

    /** @param  array<string, string>  $errors */
    private function form(Request $request, UserInvitation $invitation, array $errors = [], int $status = 200): Response
    {
        return $this->html($request, 'invitations.form', [
            'invitation' => $invitation,
            'existing' => $this->invitations->needsExistingPassword($invitation),
            'errors' => $errors,
        ], $status);
    }

    private function stateResponse(Request $request, string $state, int $status, ?UserInvitation $invitation = null, ?int $retryAfter = null): Response
    {
        $this->applyLocale($request, $invitation);

        $data = ['state' => $state, 'minutes' => $retryAfter ? max(1, (int) ceil($retryAfter / 60)) : (int) ceil(config('invitations.confirm_lockout_seconds') / 60)];

        if ($invitation && $state === InvitationException::USED) {
            $data['continueUrl'] = $this->continueUrl($invitation);
            $data['application'] = $invitation->application->name;
        }

        $response = $this->html($request, 'invitations.state', $data, $status);

        if ($retryAfter) {
            $response->headers->set('Retry-After', (string) $retryAfter);
        }

        return $response;
    }

    /** @param  array<string, mixed>  $data */
    private function html(Request $request, string $view, array $data, int $status = 200): Response
    {
        return response(View::make($view, $data)->render(), $status)->header('Content-Type', 'text/html; charset=UTF-8');
    }

    /** Where "continue" goes: the application's own sign-in entry point, which starts the normal OIDC code + PKCE flow. */
    private function continueUrl(UserInvitation $invitation): ?string
    {
        $application = $invitation->application;

        return OidcClient::query()->where('application_id', $application->id)->where('status', OidcClient::STATUS_ACTIVE)->value('launch_url')
            ?? $application->frontend_url
            ?: null;
    }
}
