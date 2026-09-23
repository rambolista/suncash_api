<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Api\Concerns\ExportsTabularReports;
use App\Http\Controllers\Controller;
use App\Services\Tools\VoucherBatchGenerationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class VoucherBatchGenerationController extends Controller
{
    use ExportsTabularReports;

    protected const MODULE_PATH = '/tools/voucher-batch-generation';

    public function __construct(private readonly VoucherBatchGenerationService $vouchers) {}

    private function invalid(ValidationException $exception): JsonResponse
    {
        return response()->json(['message' => 'The given data was invalid.', 'errors' => $exception->errors()], 422);
    }

    public function index(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['data' => $this->vouchers->listBatches()]);
    }

    public function rows(Request $request, int $batchId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['data' => $this->vouchers->listRows($batchId)]);
    }

    public function template(Request $request): Response
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $path = tempnam(sys_get_temp_dir(), 'voucher-batch-template-').'.xlsx';
        $this->vouchers->writeTemplateTo($path);
        $contents = file_get_contents($path);
        unlink($path);

        return response($contents, 200, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="Voucher Batch Sample.xlsx"',
        ]);
    }

    public function import(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        $data = $request->validate(['file' => ['required', 'file']]);

        try {
            $result = $this->vouchers->import($data['file'], $request->user());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Upload successful.'] + $result, 201);
    }

    public function process(Request $request, int $batchId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $result = $this->vouchers->processBatch($batchId, $request->user());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($result);
    }

    public function skip(Request $request, int $rowId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        return response()->json($this->vouchers->skipRow($rowId, $request->user()));
    }

    public function resend(Request $request, int $batchId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $result = $this->vouchers->resendBatch($batchId, $request->user());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($result);
    }

    public function export(Request $request): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $batchId = $request->query('batch_id') ? (int) $request->query('batch_id') : null;
        $format = (string) $request->query('format', 'csv');
        $rows = $this->vouchers->exportRows($batchId);

        return $this->exportTabularReport($request, $format, VoucherBatchGenerationService::COLUMNS, $rows, 'Voucher Batch', 'voucher-batch', array_filter(['batch_id' => $batchId]), maxPdfRows: null);
    }
}
