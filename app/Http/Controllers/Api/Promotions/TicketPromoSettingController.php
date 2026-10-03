<?php

namespace App\Http\Controllers\Api\Promotions;

use App\Http\Controllers\Controller;
use App\Services\Promotions\TicketPromoSettingService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class TicketPromoSettingController extends Controller
{
    protected const MODULE_PATH = '/promotions/ticket-settings';

    public function __construct(private readonly TicketPromoSettingService $tickets) {}

    private function invalid(ValidationException $exception): JsonResponse
    {
        $errors = $exception->errors();
        $status = array_key_exists('id', $errors) ? 404 : 422;

        return response()->json(['message' => $status === 404 ? 'Not found.' : collect($errors)->flatten()->first(), 'errors' => $errors], $status);
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json($this->tickets->list());
    }

    public function store(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_add')) {
            return $response;
        }

        try {
            $row = $this->tickets->create($request->all(), $request);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Free Ticket Promo successfully added.', 'setting' => $row], 201);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $row = $this->tickets->update($id, $request->all(), $request);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Free Ticket Promo successfully updated.', 'setting' => $row]);
    }

    public function destroy(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_delete')) {
            return $response;
        }

        try {
            $this->tickets->delete($id, $request);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Free Ticket Promo successfully deleted.']);
    }
}
