<?php

namespace App\Http\Controllers\Api\AccessManagement;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Services\Merchant\MerchantRegistrationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class MerchantController extends Controller
{
    private const MODULE_PATH = '/merchants/registration';

    public function __construct(private readonly MerchantRegistrationService $merchants)
    {
    }

    public function index(Request $request): JsonResponse
    {
        if (! $this->userHasPermission($request->user(), self::MODULE_PATH, 'can_view')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        return response()->json($this->merchants->listMerchants());
    }

    public function checkId(Request $request): JsonResponse
    {
        if (! $this->userHasPermission($request->user(), self::MODULE_PATH, 'can_view')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $clientId = trim((string) $request->query('client_id'));

        return response()->json([
            'available' => $clientId !== '' && $this->merchants->isClientIdAvailable($clientId),
        ]);
    }

    public function checkUsername(Request $request): JsonResponse
    {
        if (! $this->userHasPermission($request->user(), self::MODULE_PATH, 'can_view')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $username = trim((string) $request->query('username'));

        return response()->json([
            'available' => $username !== '' && $this->merchants->isUsernameAvailable($username),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        if (! $this->userHasPermission($request->user(), self::MODULE_PATH, 'can_add')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->all();
        $createdBy = (string) $request->user()->id;

        try {
            $result = $this->merchants->register($data, $createdBy);
        } catch (ValidationException $exception) {
            return response()->json([
                'message' => 'The given data was invalid.',
                'errors' => $exception->errors(),
            ], 422);
        }

        ActivityLog::recordAction($request->user(), 'Merchants', 'created', "Registered merchant {$result['client_id']}.", null, $request);

        return response()->json([
            'message' => 'Merchant registered successfully.',
            'client_record_id' => $result['client_record_id'],
            'client_id' => $result['client_id'],
        ], 201);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if (! $this->userHasPermission($request->user(), self::MODULE_PATH, 'can_view')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        try {
            return response()->json($this->merchants->getMerchant($id));
        } catch (ValidationException $exception) {
            return response()->json(['message' => 'Merchant not found.'], 404);
        }
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if (! $this->userHasPermission($request->user(), self::MODULE_PATH, 'can_edit')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $data = $request->all();
        $updatedBy = (string) $request->user()->id;

        try {
            $result = $this->merchants->update($id, $data, $updatedBy);
        } catch (ValidationException $exception) {
            $status = array_key_exists('id', $exception->errors()) ? 404 : 422;

            return response()->json([
                'message' => $status === 404 ? 'Merchant not found.' : 'The given data was invalid.',
                'errors' => $exception->errors(),
            ], $status);
        }

        ActivityLog::recordAction($request->user(), 'Merchants', 'updated', "Updated merchant {$result['client_id']}.", null, $request);

        return response()->json([
            'message' => 'Merchant updated successfully.',
            'client_record_id' => $result['client_record_id'],
            'client_id' => $result['client_id'],
        ]);
    }

    public function uploadLogo(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->userHasPermission($user, self::MODULE_PATH, 'can_add') && ! $this->userHasPermission($user, self::MODULE_PATH, 'can_edit')) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        $validator = Validator::make($request->all(), [
            'logo' => ['required', 'image', 'max:5120'],
        ]);

        if ($validator->fails()) {
            return response()->json(['message' => 'The given data was invalid.', 'errors' => $validator->errors()], 422);
        }

        $path = $request->file('logo')->store('merchant-logos', 'public');

        return response()->json([
            'path' => $path,
            'url' => Storage::disk('public')->url($path),
        ], 201);
    }
}
