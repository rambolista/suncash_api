<?php

namespace App\Http\Controllers\Api\Tools;

use App\Http\Controllers\Api\Concerns\ExportsTabularReports;
use App\Http\Controllers\Controller;
use App\Services\Customer\CustomerArchiveService;
use App\Services\Tools\AccountStatusService;
use App\Services\Tools\AuthenticateUserService;
use App\Services\Tools\ComplyAdvantageService;
use App\Services\Tools\CustomerLinkedAccountsService;
use App\Services\Tools\CustomerManagementService;
use App\Services\Tools\CustomerPromoService;
use App\Services\Tools\PushNotificationService;
use App\Services\Tools\ResetPinService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class CustomerManagementController extends Controller
{
    use ExportsTabularReports;

    protected const MODULE_PATH = '/tools/customer-management';

    public function __construct(
        private readonly CustomerManagementService $customers,
        private readonly ComplyAdvantageService $comply,
        private readonly AuthenticateUserService $authenticateUser,
        private readonly CustomerArchiveService $archive,
        private readonly AccountStatusService $accountStatus,
        private readonly CustomerLinkedAccountsService $linkedAccounts,
        private readonly ResetPinService $resetPin,
        private readonly CustomerPromoService $promo,
        private readonly PushNotificationService $pushNotification,
    ) {}

    private function invalid(ValidationException $exception): JsonResponse
    {
        $status = array_key_exists('id', $exception->errors()) ? 404 : 422;

        return response()->json([
            'message' => $status === 404 ? 'Not found.' : 'The given data was invalid.',
            'errors' => $exception->errors(),
        ], $status);
    }

    public function search(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            $data = $this->customers->search($request->only(['first_name', 'last_name', 'mobile_number', 'card_number', 'email', 'bank_topup']));
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['data' => $data]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            $detail = $this->customers->detail($id);
            $detail['notes'] = $this->customers->notes($id);
            $detail['transactions'] = $this->archive->recentTransactions($id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($detail);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        $data = $request->validate([
            'first_name' => ['nullable', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['nullable', 'string', 'max:100'],
            'email' => ['nullable', 'email', 'max:150'],
            'gender' => ['nullable', 'string', 'max:20'],
            'country' => ['nullable', 'string', 'max:100'],
            'birthday' => ['nullable', 'date'],
            'address1' => ['nullable', 'string', 'max:255'],
            'address2' => ['nullable', 'string', 'max:255'],
            'zip' => ['nullable', 'string', 'max:20'],
            'island' => ['nullable', 'integer'],
            'city' => ['nullable', 'integer'],
            'customer_tag' => ['nullable', 'string', 'max:50'],
            'risk_rating' => ['nullable', 'string', 'max:20'],
            'occupation' => ['nullable', 'integer'],
            'employment_position_level' => ['nullable', 'integer'],
            'sms_notification' => ['nullable', 'boolean'],
            'email_notification' => ['nullable', 'boolean'],
            'is_locked' => ['nullable', 'boolean'],
            'is_card_beta_user' => ['nullable', 'boolean'],
            'is_pep' => ['nullable', 'boolean'],
        ]);

        try {
            $detail = $this->customers->update($id, $data, $request->user());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Customer profile has been updated.', 'data' => $detail]);
    }

    public function addNote(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'note' => ['required', 'string', 'max:2000'],
        ]);

        try {
            $note = $this->customers->addNote($id, $data['title'], $data['note'], $request->user());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Note has been added.', 'data' => $note], 201);
    }

    public function transactions(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $validated = $request->validate([
            'from' => ['required', 'date'],
            'to' => ['required', 'date'],
        ]);

        try {
            $data = $this->archive->transactionsInRange($id, $validated['from'], $validated['to']);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['data' => $data]);
    }

    public function exportTransactions(Request $request, int $id): JsonResponse|StreamedResponse|Response
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $format = (string) $request->query('format', 'csv');
        $from = $request->query('from');
        $to = $request->query('to');

        try {
            $rows = ($from && $to)
                ? $this->archive->transactionsInRange($id, $from, $to)
                : $this->archive->recentTransactions($id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return $this->exportTabularReport($request, $format, CustomerArchiveService::COLUMNS, $rows, 'Customer Transaction History', 'customer-transactions', array_filter(['from' => $from, 'to' => $to]), maxPdfRows: null);
    }

    public function archive(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_delete')) {
            return $response;
        }

        try {
            $result = $this->archive->archive($id, (string) $request->user()->id, (string) $request->ip());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($result);
    }

    public function complyProfile(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            $profile = $this->comply->getProfile($id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($profile);
    }

    public function authenticateStatus(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['data' => $this->authenticateUser->activeRequest($id)]);
    }

    public function authenticateRequest(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_execute')) {
            return $response;
        }

        $data = $request->validate([
            'method' => ['required', 'string', 'in:sms,email'],
            'reason' => ['required', 'string'],
            'reason_other' => ['nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $this->authenticateUser->request($id, $data['method'], $data['reason'], $data['reason_other'] ?? null, $request->user());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Authentication request has been sent.', 'data' => $result]);
    }

    public function accountStatusReasons(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json([
            'locked' => $this->accountStatus->lockReasons(),
            'restricted' => $this->accountStatus->restrictionReasons(),
            'restoration' => $this->accountStatus->restoreReasons(),
        ]);
    }

    public function accountStatusHistory(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        $data = $request->validate([
            'start_date' => ['required', 'date'],
            'end_date' => ['required', 'date'],
            'status' => ['nullable', 'string', 'in:A,L,R'],
        ]);

        return response()->json(['data' => $this->accountStatus->history($id, $data['start_date'], $data['end_date'], $data['status'] ?? null)]);
    }

    public function updateAccountStatus(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        $data = $request->validate([
            'current_status' => ['required', 'string'],
            'new_status' => ['required', 'string'],
            'reason_id' => ['required', 'integer'],
            'reason_label' => ['required', 'string', 'max:255'],
            'note' => ['required', 'string'],
            'change_type' => ['required', 'string', 'in:locked,restricted,restore'],
            'reference' => ['required', 'string', 'max:110'],
        ]);

        try {
            $result = $this->accountStatus->updateStatus(
                $id,
                $data['current_status'],
                $data['new_status'],
                $data['reason_id'],
                $data['reason_label'],
                $data['note'],
                $data['change_type'],
                $data['reference'],
                $request->user(),
            );
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Account Status has been updated.', 'data' => $result]);
    }

    public function linkedCards(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['data' => $this->linkedAccounts->cards($id)]);
    }

    public function deleteLinkedCard(Request $request, int $cardId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_delete')) {
            return $response;
        }

        try {
            $this->linkedAccounts->deleteCard($cardId, $request->user());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => 'Customer card successfully deleted.']);
    }

    public function linkedBankAccounts(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['data' => $this->linkedAccounts->bankAccounts($id)]);
    }

    public function scannedIds(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        try {
            $data = $this->linkedAccounts->scannedIds($id);
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($data);
    }

    public function resetPin(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        try {
            $result = $this->resetPin->reset($id, $request->user());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($result);
    }

    public function dropdowns(Request $request): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json([
            'countries' => $this->customers->countries(),
            'islands' => $this->customers->islands(),
        ]);
    }

    public function citiesByIsland(Request $request, int $islandId): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['data' => $this->customers->citiesByIsland($islandId)]);
    }

    public function updateScannedIds(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        $data = $request->validate([
            'id_card_type' => ['nullable', 'string', 'max:20'],
            'id_card_num' => ['nullable', 'string', 'max:50'],
            'id_card_expiry' => ['nullable', 'string', 'max:20'],
            'id_card_issue_date' => ['nullable', 'string', 'max:20'],
            'scanned_id' => ['nullable', 'string'],
            'secondary_id_card_type' => ['nullable', 'string', 'max:20'],
            'secondary_id_card_num' => ['nullable', 'string', 'max:50'],
            'secondary_id_card_expiry' => ['nullable', 'string', 'max:20'],
            'secondary_scanned_id' => ['nullable', 'string'],
        ]);

        try {
            $result = $this->linkedAccounts->updateScannedIds($id, $data, $request->user());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json(['message' => "Customer scanned ID's has been updated.", 'data' => $result]);
    }

    public function promoStatus(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_view')) {
            return $response;
        }

        return response()->json(['is_active' => $this->promo->isActive($id)]);
    }

    public function updatePromoStatus(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_edit')) {
            return $response;
        }

        $data = $request->validate(['is_active' => ['required', 'boolean']]);
        $this->promo->setActive($id, $data['is_active'], $request->user());

        return response()->json(['message' => 'Preferences have been updated.']);
    }

    public function sendPushNotification(Request $request, int $id): JsonResponse
    {
        if ($response = $this->forbidden($request, 'can_execute')) {
            return $response;
        }

        $data = $request->validate(['type' => ['required', 'string', 'in:kyc,version']]);

        try {
            $result = $this->pushNotification->send($id, $data['type'], $request->user());
        } catch (ValidationException $exception) {
            return $this->invalid($exception);
        }

        return response()->json($result);
    }
}
