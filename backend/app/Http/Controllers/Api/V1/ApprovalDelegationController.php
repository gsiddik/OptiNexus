<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Api\V1\Concerns\ApiResponses;
use App\Http\Controllers\Controller;
use App\Http\Requests\V1\StoreApprovalDelegationRequest;
use App\Http\Resources\V1\ApprovalDelegationResource;
use App\Models\ApprovalDelegation;
use App\Models\User;
use App\Services\Approval\ApprovalService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ApprovalDelegationController extends Controller
{
    use ApiResponses;

    public function __construct(private readonly ApprovalService $approvals) {}

    public function store(StoreApprovalDelegationRequest $request): JsonResponse
    {
        $data = $request->validated();
        $delegate = User::findOrFail($data['delegate_user_id']);

        $delegation = $this->approvals->createDelegation(
            $request->user(),
            $delegate,
            $data['approval_definition_id'] ?? null,
            $data['tenant_id'] ?? null,
            isset($data['starts_at']) ? \Illuminate\Support\Carbon::parse($data['starts_at']) : null,
            isset($data['ends_at']) ? \Illuminate\Support\Carbon::parse($data['ends_at']) : null,
        );

        return $this->created(new ApprovalDelegationResource($delegation));
    }

    public function destroy(Request $request, ApprovalDelegation $delegation): JsonResponse
    {
        if ($delegation->delegator_user_id !== $request->user()->id) {
            return $this->fail('UNAUTHORIZED', 'You may only revoke delegations you created.', 403);
        }

        $this->approvals->revokeDelegation($delegation, $request->user());

        return $this->ok(new ApprovalDelegationResource($delegation->refresh()));
    }
}
