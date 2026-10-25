<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Resources\V1\EmailSuppressionResource;
use App\Models\EmailSuppression;
use App\Services\Notification\EmailSuppressionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class EmailSuppressionController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly EmailSuppressionService $suppressions) {}

    public function index(Request $request): JsonResponse
    {
        $query = EmailSuppression::query()->with('releaser');
        if ($search = $request->query('search')) {
            $query->where('email', 'like', '%'.mb_strtolower((string) $search).'%');
        }
        match ($request->query('state')) {
            'active' => $query->whereNull('released_at'),
            'released' => $query->whereNotNull('released_at'),
            default => null,
        };

        $paginator = $query->orderByDesc('suppressed_at')->paginate(min(100, max(1, (int) $request->query('per_page', 20))));

        return $this->paginated($paginator, EmailSuppressionResource::class);
    }

    public function release(Request $request, EmailSuppression $emailSuppression): JsonResponse
    {
        if ($error = $this->suppressions->release($emailSuppression, $request->user())) {
            return $this->fail($error, 'This address is not suppressed any more.', 409);
        }

        return $this->ok(new EmailSuppressionResource($emailSuppression->refresh()->load('releaser')));
    }
}
