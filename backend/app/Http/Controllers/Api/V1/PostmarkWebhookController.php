<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Services\Notification\EmailSuppressionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Postmark bounce, spam-complaint and subscription-change webhook. Postmark has no payload signature; the endpoint is protected
 * with HTTP Basic credentials that are part of the webhook URL configured in Postmark (config/notifications.php). Until both are
 * configured the endpoint does not exist (404), so an unconfigured deployment cannot be fed fake suppressions.
 */
class PostmarkWebhookController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly EmailSuppressionService $suppressions) {}

    public function __invoke(Request $request): JsonResponse
    {
        $username = (string) config('notifications.postmark_webhook.username');
        $password = (string) config('notifications.postmark_webhook.password');
        if ($username === '' || $password === '') {
            return $this->fail('NOT_FOUND', 'Not found.', 404);
        }
        if (! hash_equals($username, (string) $request->getUser()) || ! hash_equals($password, (string) $request->getPassword())) {
            return $this->fail('UNAUTHENTICATED', 'Invalid webhook credentials.', 401);
        }

        return $this->ok(['result' => $this->suppressions->handlePostmark($request->json()->all())]);
    }
}
