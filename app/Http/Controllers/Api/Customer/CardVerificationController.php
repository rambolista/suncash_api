<?php

namespace App\Http\Controllers\Api\Customer;

use App\Http\Controllers\Controller;
use App\Services\Customer\CardVerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CardVerificationController extends Controller
{
    protected const MODULE_PATH = '/customers/card-verification';

    public function __construct(private readonly CardVerificationService $cards) {}

    private function invalid(ValidationException $exception): JsonResponse
    {
        $status = array_key_exists('id', $exception->errors()) ? 404 : 422;

        return response()->json([
            'message' => $status === 404 ? 'Not found.' : 'The given data was invalid.',
            'errors' => $exception->errors(),
        ], $status);
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:pending,approved,rejected,blacklisted'],
            'page' => ['sometimes', 'integer', 'min:1'],
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'created_at' => ['sometimes', 'nullable', 'string', 'max:20'],
            'cardholder_name' => ['sometimes', 'nullable', 'string', 'max:100'],
            'mobile' => ['sometimes', 'nullable', 'string', 'max:32'],
            'card_last_four_digits' => ['sometimes', 'nullable', 'string', 'max:10'],
            'card_type' => ['sometimes', 'nullable', 'string', 'max:30'],
            'rejected_reason' => ['sometimes', 'nullable', 'string', 'max:100'],
        ]);

        $columnFilters = collect($validated)
            ->only(['created_at', 'cardholder_name', 'mobile', 'card_last_four_digits', 'card_type', 'rejected_reason'])
            ->filter()
            ->all();

        $page = $this->cards->paginatedList($validated['status'] ?? 'pending', (int) ($validated['page'] ?? 1), $validated['search'] ?? null, $columnFilters);

        return response()->json($page + [
            'counts' => $this->cards->counts(),
            'reject_reasons' => CardVerificationService::REJECT_REASONS,
            'blacklist_reasons' => CardVerificationService::BLACKLIST_REASONS,
        ]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            $result = $this->cards->getDetail($id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($result);
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_approve')) {
            return $response;
        }

        try {
            $result = $this->cards->approve($id, (string) $request->user()->id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Card has been approved.'] + $result);
    }

    public function reject(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_approve')) {
            return $response;
        }

        try {
            $result = $this->cards->reject($id, (string) $request->input('reason'), (string) $request->user()->id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Card has been rejected.'] + $result);
    }

    public function blacklist(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_approve')) {
            return $response;
        }

        try {
            $result = $this->cards->blacklist($id, (string) $request->input('reason'), (string) $request->user()->id, (string) $request->ip());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Card has been blacklisted.'] + $result);
    }
}
