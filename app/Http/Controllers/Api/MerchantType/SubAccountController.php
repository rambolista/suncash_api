<?php

namespace App\Http\Controllers\Api\MerchantType;

use App\Http\Controllers\Controller;
use App\Models\ActivityLog;
use App\Models\Mysuncash\Merchant;
use App\Services\MerchantType\SubAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;

class SubAccountController extends Controller
{
    private const MODULE_PATH = '/merchants/business-management';

    public function __construct(private readonly SubAccountService $subAccounts) {}

    private function forbidden(Request $request, string $action): ?JsonResponse
    {
        return $this->userHasPermission($request->user(), self::MODULE_PATH, $action)
            ? null
            : response()->json(['message' => 'Forbidden.'], 403);
    }

    private function invalid(ValidationException $exception): JsonResponse
    {
        $status = array_key_exists('id', $exception->errors()) ? 404 : 422;

        return response()->json([
            'message' => $status === 404 ? 'Not found.' : 'The given data was invalid.',
            'errors' => $exception->errors(),
        ], $status);
    }

    private function actorName(Request $request): string
    {
        return (string) ($request->user()->name ?? $request->user()->email);
    }

    public function template(Request $request): Response
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $path = tempnam(sys_get_temp_dir(), 'subaccount-template-').'.xlsx';
        $this->subAccounts->writeTemplateTo($path);
        $contents = file_get_contents($path);
        unlink($path);

        return response($contents, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Sub Account Sample.xlsx"',
        ]);
    }

    public function import(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        $request->validate(['file' => ['required', 'file']]);

        try {
            $result = $this->subAccounts->import($id, $request->file('file'), $this->actorName($request));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        ActivityLog::recordAction($request->user(), 'Sub Accounts', 'import', "Imported {$result['imported']} sub account(s) ({$result['skipped']} skipped) for business #{$id}", Merchant::find($id), $request);

        return response()->json(['message' => 'Sub accounts imported successfully.'] + $result);
    }
}
