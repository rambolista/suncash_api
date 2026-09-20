<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Controller;
use App\Services\Tools\SendSmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class SendSmsController extends Controller
{
    protected const MODULE_PATH = '/tools/send-sms';

    public function __construct(private readonly SendSmsService $sendSms) {}

    public function recipientCount(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['count' => $this->sendSms->recipientCount()]);
    }

    public function send(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_execute')) {
            return $response;
        }

        $data = $request->validate([
            'mobile_number' => ['nullable', 'string', 'max:20'],
            'message' => ['required', 'string', 'max:145'],
        ]);

        try {
            $result = $this->sendSms->send($data['mobile_number'] ?? null, $data['message'], $request->user());
        } catch (ValidationException $exception) {
            return response()->json(['message' => 'The given data was invalid.', 'errors' => $exception->errors()], 422);
        }

        $gateway = ucfirst((string) $result['gateway']);
        $message = $result['simulated']
            ? "{$gateway} SMS sending is disabled in this environment — no messages were actually sent ({$result['total']} would have been targeted)."
            : "Done sending SMS. {$result['sent']} of {$result['total']} succeeded.";

        return response()->json(['message' => $message, 'data' => $result]);
    }
}
