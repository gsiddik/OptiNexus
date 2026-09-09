<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\AccessEvaluateRequest;
use App\Services\Access\AccessEvaluationService;
use Illuminate\Http\JsonResponse;

class AccessController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly AccessEvaluationService $access) {}

    public function evaluate(AccessEvaluateRequest $request): JsonResponse
    {
        return $this->ok($this->access->evaluate($request->validated()));
    }
}
