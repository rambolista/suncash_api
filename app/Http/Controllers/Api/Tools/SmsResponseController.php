<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Controller;
use App\Services\Tools\SmsResponseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SmsResponseController extends Controller
{
    protected const MODULE_PATH = '/tools/sms-responses';

    public function __construct(private readonly SmsResponseService $smsResponses) {}

    public function merchants(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['data' => $this->smsResponses->merchants()]);
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $merchantId = (string) $request->query('merchant_id', '0');
        if (! ctype_digit($merchantId)) {
            return response()->json(['message' => 'Invalid merchant selected.'], 422);
        }

        return response()->json(['data' => $this->smsResponses->list($merchantId)]);
    }

    public function update(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        $data = $request->validate([
            'merchant_id' => ['required', 'string'],
            'response_title' => ['required', 'string'],
            'message_template' => ['required', 'string'],
        ]);

        try {
            $result = $this->smsResponses->update($data['merchant_id'], $data['response_title'], $data['message_template'], $request->user());
        } catch (ValidationException $exception) {
            return response()->json(['message' => $exception->errors()['message_template'][0] ?? 'The given data was invalid.', 'errors' => $exception->errors()], 422);
        }

        return response()->json(['message' => 'Successfully saved template.', 'data' => $result]);
    }
}
