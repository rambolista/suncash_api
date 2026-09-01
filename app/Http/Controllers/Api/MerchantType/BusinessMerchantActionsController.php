<?php

namespace App\Http\Controllers\Api\MerchantType;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Mysuncash\Merchant;
use App\Services\MerchantType\BusinessMerchantActionsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The 7 net-new settings buttons on Business Management's Approved tab
 * (Card Hold Settings, Suncash Transaction Fee, Authorized Auth, GC Fee,
 * Voucher Setting, credit/debit card review). Gated by Business
 * Management's own can_edit — not Merchant Management's — matching the
 * scoping fix already applied to the Activate action.
 */
class BusinessMerchantActionsController extends Controller
{
    private const MODULE_PATH = '/merchants/business-management';

    public function __construct(private readonly BusinessMerchantActionsService $actions) {}

    private function forbidden(Request $request, string $action): ?JsonResponse
    {
        return $this->userHasPermission($request->user(), self::MODULE_PATH, $action)
            ? null
            : response()->json(['message' => 'Forbidden.'], 403);
    }

    private function invalid(ValidationException $exception): JsonResponse
    {
        $status = array_key_exists('id', $exception->errors()) || array_key_exists('card_id', $exception->errors()) ? 404 : 422;
        // 'card_action' (already approved/rejected, CenPOS errors) is a business-rule/service failure, not a 404.

        return response()->json([
            'message' => $status === 404 ? 'Not found.' : 'The given data was invalid.',
            'errors' => $exception->errors(),
        ], $status);
    }

    private function actorName(Request $request): string
    {
        return (string) ($request->user()->name ?? $request->user()->email);
    }

    public function updateCardHoldSettings(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $data = $this->actions->updateCardHoldDays($id, $request->all(), $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Business Management', 'update', "Updated card hold days to {$data['card_hold_days']} for business #{$id}", Merchant::find($id), $request);

        return response()->json(['message' => 'Card hold settings updated successfully.'] + $data);
    }

    public function updateTransactionFee(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $data = $this->actions->updateTransactionFee($id, $request->all(), $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Business Management', 'update', "Updated Suncash transaction fee to {$data['suncash_transaction_fee']} for business #{$id}", Merchant::find($id), $request);

        return response()->json(['message' => 'Suncash transaction fee updated successfully.'] + $data);
    }

    public function updateAuthorizedAuth(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $data = $this->actions->updateAuthorizedAuth($id, $request->all(), $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Business Management', 'update', "Updated authorized auth settings for business #{$id} (limit: {$data['reauth_amount_limit']}, hold days: {$data['reauth_card_hold_days']})", Merchant::find($id), $request);

        return response()->json(['message' => 'Authorized auth settings updated successfully.'] + $data);
    }

    public function updateGcFee(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $data = $this->actions->updateGcFee($id, $request->all(), $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Business Management', 'update', "Updated GC fee to {$data['gc_fee']} for business #{$id}", Merchant::find($id), $request);

        return response()->json(['message' => 'GC fee updated successfully.'] + $data);
    }

    public function getVoucherSettings(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            $data = $this->actions->getVoucherSettings($id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($data);
    }

    public function updateVoucherSettings(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $data = $this->actions->updateVoucherSettings($id, $request->all(), $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Business Management', 'update', "Updated voucher settings for business #{$id}", Merchant::find($id), $request);

        return response()->json(['message' => 'Voucher setting updated successfully.'] + $data);
    }

    public function listLinkedCards(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            $data = $this->actions->listLinkedCards($id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['data' => $data]);
    }

    public function approveCard(Request $request, int $id, int $cardId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $data = $this->actions->approveCard($id, $cardId, $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Business Management', 'approve_card', "Approved linked card #{$cardId} for business #{$id}", Merchant::find($id), $request);

        return response()->json(['message' => 'Card approved successfully.'] + $data);
    }

    public function rejectCard(Request $request, int $id, int $cardId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        $reason = (string) $request->input('reason', '');
        if (trim($reason) === '') {
            return response()->json(['message' => 'The given data was invalid.', 'errors' => ['reason' => ['A rejection reason is required.']]], 422);
        }

        try {
            $data = $this->actions->rejectCard($id, $cardId, $reason, $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Business Management', 'reject_card', "Rejected linked card #{$cardId} for business #{$id} ({$reason})", Merchant::find($id), $request);

        return response()->json(['message' => 'Card rejected successfully.'] + $data);
    }
}
