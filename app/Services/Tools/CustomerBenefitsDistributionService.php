<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\Mysuncash\Customer;
use App\Models\Mysuncash\CustomerBenefit;
use App\Models\Mysuncash\CustomerBenefitBatch;
use App\Models\Mysuncash\Merchant;
use App\Models\User;
use App\Services\Transactions\Support\LedgerAdjuster;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * "Tools > Customer Benefits Distribution" (legacy `tools/customer_benefits` +
 * `tools_model::load_customer_benefits()`/`process_customer_benefits()`/
 * `process_customers_benefits_per_customer()`) — bulk-credits an existing
 * customer's card balance (`ezkard_accounts.card_balance`) from an uploaded
 * list of recipients, matched by NIB number. Unlike Voucher Batch Generation,
 * this never creates a new redeemable artifact and never notifies the
 * recipient — it's a direct wallet top-up, straight off legacy's own
 * behavior (which sends no SMS/email for this feature either).
 *
 * All money movement goes through `LedgerAdjuster`, already built for Void
 * Transaction to reuse this exact `clients.client_prefund` /
 * `ezkard_accounts.card_balance` adjustment logic instead of re-deriving it.
 * Distribution is funded from a pre-existing `clients` clearing account
 * (`client_id = 'CUSTOMER_BENEFITS_DISTRIBUTION'`) the same way Business
 * Billpay assumes its own `BUSINESSBP` clearing account already exists,
 * rather than legacy's runtime auto-creation of it.
 *
 * Legacy reserves the batch's full amount against the clearing account's
 * prefund balance up front on upload, then draws it back down per recipient
 * as each is actually paid — kept here rather than simplified away, since
 * changing that changes real ledger/reconciliation behavior, not just code
 * shape.
 *
 * Legacy auto-processes the whole batch immediately on upload (no separate
 * "confirm to process" step for a fresh batch); the page's Process buttons
 * exist only to retry rows still stuck `active` (most commonly: no customer
 * matched that NIB number yet). Legacy also processes those retries with a
 * generic "successful" response even when a row was silently skipped for a
 * missing customer match — this reports an honest paid/skipped breakdown
 * instead.
 *
 * Legacy's parser doesn't skip a header row (it starts at row 1); the sample
 * file it hands out for reference has one, so parsing here starts at row 2,
 * matching every other Excel import in this codebase (same fix already made
 * for Voucher Batch Generation).
 */
class CustomerBenefitsDistributionService
{
    private const PROCESS_LIMIT = 500;

    private const CLEARING_CLIENT_ID = 'CUSTOMER_BENEFITS_DISTRIBUTION';

    public const COLUMNS = [
        ['key' => 'name', 'label' => 'Name'],
        ['key' => 'mobile', 'label' => 'Mobile'],
        ['key' => 'amount', 'label' => 'Amount'],
        ['key' => 'email', 'label' => 'Email'],
        ['key' => 'issuer', 'label' => 'Issuer'],
        ['key' => 'issued_id', 'label' => 'NIB Number'],
        ['key' => 'status_label', 'label' => 'Status'],
    ];

    public function __construct(private readonly LedgerAdjuster $ledger) {}

    public function listBatches(): array
    {
        $counts = CustomerBenefit::query()
            ->selectRaw('batch_id, status, count(*) as total')
            ->groupBy('batch_id', 'status')
            ->get()
            ->groupBy('batch_id');

        return CustomerBenefitBatch::orderByDesc('timestamp')->get()->map(function (CustomerBenefitBatch $batch) use ($counts) {
            $byStatus = ($counts->get($batch->id) ?? collect())->pluck('total', 'status');

            return [
                'id' => $batch->id,
                'batch_name' => $batch->batch_name,
                'timestamp' => $batch->timestamp,
                'total' => (int) $byStatus->sum(),
                'active' => (int) ($byStatus[CustomerBenefit::STATUS_ACTIVE] ?? 0),
                'paid' => (int) ($byStatus[CustomerBenefit::STATUS_PAID] ?? 0),
            ];
        })->all();
    }

    /** "All" tab — every row in one batch, regardless of status. */
    public function rows(int $batchId): array
    {
        return CustomerBenefit::where('batch_id', $batchId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (CustomerBenefit $row) => $this->mapRow($row))
            ->all();
    }

    /** "Active" tab — every still-unpaid row, across all batches. */
    public function activeList(): array
    {
        return $this->statusList(CustomerBenefit::STATUS_ACTIVE);
    }

    /** "Paid" tab — every already-paid row, across all batches. */
    public function paidList(): array
    {
        return $this->statusList(CustomerBenefit::STATUS_PAID);
    }

    private function statusList(string $status): array
    {
        return CustomerBenefit::with('batch')
            ->where('status', $status)
            ->orderByDesc('id')
            ->get()
            ->map(fn (CustomerBenefit $row) => $this->mapRow($row, withBatchName: true))
            ->all();
    }

    private function mapRow(CustomerBenefit $row, bool $withBatchName = false): array
    {
        return [
            'id' => $row->id,
            'name' => $row->name,
            'mobile' => $row->mobile,
            'amount' => (float) $row->amount,
            'email' => $row->email,
            'issuer' => $row->issuer,
            'issued_id' => $row->issued_id,
            'status' => $row->status,
            'status_label' => ucfirst($row->status),
            'batch_name' => $withBatchName ? $row->batch?->batch_name : null,
        ];
    }

    public function exportRows(?int $batchId = null): array
    {
        $query = CustomerBenefit::query();
        if ($batchId) {
            $query->where('batch_id', $batchId);
        }

        return $query->orderByDesc('id')->get()->map(fn (CustomerBenefit $row) => $this->mapRow($row, withBatchName: true))->all();
    }

    public function template(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $columns = ['A', 'B', 'C', 'D', 'E', 'F'];

        foreach (['Name', 'Mobile', 'Amount', 'Email', 'Issuer', 'NIB Number'] as $index => $label) {
            $sheet->setCellValue($columns[$index].'1', $label);
        }

        foreach (['Jane Doe', '12425551234', '50.00', 'jane@example.com', 'NIB', '12345678'] as $index => $value) {
            $sheet->setCellValue($columns[$index].'2', $value);
        }

        return $spreadsheet;
    }

    public function writeTemplateTo(string $path): void
    {
        (new Xlsx($this->template()))->save($path);
    }

    /**
     * @throws ValidationException
     */
    public function import(UploadedFile $file, User $actor): array
    {
        $extension = strtoupper($file->getClientOriginalExtension());
        if (! in_array($extension, ['XLS', 'XLSX'], true)) {
            throw ValidationException::withMessages(['file' => ['Please upload an .xlsx or .xls file.']]);
        }

        try {
            $sheet = IOFactory::load($file->getRealPath())->getActiveSheet();
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['file' => ['Unable to read this file: '.$exception->getMessage()]]);
        }

        $rows = $this->parseRows($sheet);
        $clearingId = $this->clearingMerchantId();

        $batchId = DB::connection('mysuncash')->transaction(function () use ($rows, $file) {
            $batch = CustomerBenefitBatch::create(['batch_name' => $file->getClientOriginalName()]);

            CustomerBenefit::insert(array_map(fn (array $row) => $row + [
                'batch_id' => $batch->id,
                'status' => CustomerBenefit::STATUS_ACTIVE,
            ], $rows));

            return $batch->id;
        });

        $this->ledger->adjustClientBalance(
            $clearingId, 'prefund', 'add', array_sum(array_column($rows, 'amount')),
            73, 'Customer Benefits Distribution (Reserved)', $this->ledger->nextTransactionId()
        );

        ActivityLog::recordAction($actor, 'Tools - Customer Benefits Distribution', 'imported', "Uploaded batch #{$batchId} (".count($rows).' recipients)');

        $result = $this->payRows(
            CustomerBenefit::where('batch_id', $batchId)->where('status', CustomerBenefit::STATUS_ACTIVE)->limit(self::PROCESS_LIMIT)->get(),
            $actor,
            "batch #{$batchId}"
        );

        return ['batch_id' => $batchId, 'imported' => count($rows)] + $result;
    }

    /**
     * @throws ValidationException
     */
    private function parseRows($sheet): array
    {
        $highestRow = $sheet->getHighestRow();
        $rows = [];
        $errors = [];
        $hasAtLeastOneRow = false;

        for ($rowNumber = 2; $rowNumber <= $highestRow; $rowNumber++) {
            $name = trim((string) ($sheet->getCell('A'.$rowNumber)->getValue() ?? ''));
            $mobile = trim((string) ($sheet->getCell('B'.$rowNumber)->getValue() ?? ''));
            $amount = trim((string) ($sheet->getCell('C'.$rowNumber)->getValue() ?? ''));
            $email = trim((string) ($sheet->getCell('D'.$rowNumber)->getValue() ?? ''));
            $issuer = trim((string) ($sheet->getCell('E'.$rowNumber)->getValue() ?? ''));
            $issuedId = trim((string) ($sheet->getCell('F'.$rowNumber)->getValue() ?? ''));

            if ($name === '' && $mobile === '' && $amount === '' && $email === '' && $issuer === '' && $issuedId === '') {
                continue;
            }
            $hasAtLeastOneRow = true;

            $rowErrors = [];
            if ($mobile === '' && $email === '') {
                $rowErrors[] = "[Row {$rowNumber}] Provide a mobile number or email address.";
            }
            if ($amount === '' || ! is_numeric($amount)) {
                $rowErrors[] = "[Row {$rowNumber}] [Amount] is invalid.";
            }
            if ($issuer === '') {
                $rowErrors[] = "[Row {$rowNumber}] [Issuer] is required.";
            }

            if ($rowErrors) {
                $errors = array_merge($errors, $rowErrors);

                continue;
            }

            $rows[] = [
                'name' => $name,
                'mobile' => $mobile,
                'amount' => (float) $amount,
                'email' => $email,
                'issuer' => $issuer,
                'issued_id' => $issuedId,
            ];
        }

        if (! $hasAtLeastOneRow) {
            throw ValidationException::withMessages(['file' => ['No data found.']]);
        }
        if ($errors) {
            throw ValidationException::withMessages(['rows' => $errors]);
        }

        return $rows;
    }

    /**
     * Re-processes up to 500 of this batch's still-`active` rows (legacy's own per-call cap).
     *
     * @throws ValidationException
     */
    public function processBatch(int $batchId, User $actor): array
    {
        $rows = CustomerBenefit::where('batch_id', $batchId)
            ->where('status', CustomerBenefit::STATUS_ACTIVE)
            ->limit(self::PROCESS_LIMIT)
            ->get();

        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['batch_id' => ['Nothing to process.']]);
        }

        return $this->payRows($rows, $actor, "batch #{$batchId}");
    }

    /**
     * @throws ValidationException
     */
    public function processRow(int $rowId, User $actor): array
    {
        $row = CustomerBenefit::find($rowId);
        if (! $row || $row->status !== CustomerBenefit::STATUS_ACTIVE) {
            throw ValidationException::withMessages(['row_id' => ['This benefit is not pending processing.']]);
        }

        return $this->payRows(collect([$row]), $actor, "row #{$rowId}");
    }

    private function payRows($rows, User $actor, string $label): array
    {
        $clearingId = $this->clearingMerchantId();
        $paid = 0;
        $skipped = 0;

        foreach ($rows as $row) {
            if ($this->payRow($row, $clearingId)) {
                $paid++;
            } else {
                $skipped++;
            }
        }

        $message = "Processed {$paid} benefit(s).".($skipped ? " {$skipped} skipped (no matching customer)." : '');
        ActivityLog::recordAction($actor, 'Tools - Customer Benefits Distribution', 'processed', ucfirst($label)." processed: {$paid} paid, {$skipped} skipped");

        return ['message' => $message, 'paid' => $paid, 'skipped' => $skipped];
    }

    /** Credits the matched customer's card and drains that amount back off the clearing account's reserve. */
    private function payRow(CustomerBenefit $row, int $clearingId): bool
    {
        $customer = Customer::where('nib_number', $row->issued_id)->first();
        if (! $customer || ! $customer->ezkard_account_id) {
            return false;
        }

        DB::connection('mysuncash')->transaction(function () use ($row, $customer, $clearingId) {
            $ezkardTxn = $this->ledger->adjustCardBalance(
                $customer->ezkard_account_id, 'add', (float) $row->amount,
                73, 'Customer Benefits Distribution', $clearingId
            );

            $this->ledger->adjustClientBalance(
                $clearingId, 'prefund', 'less', (float) $row->amount,
                74, 'Customer Benefits Distribution', $ezkardTxn->transaction_id
            );

            $this->ledger->logCustomerHistory(
                $customer, $customer->ezkard_account_id, $ezkardTxn->transaction_id,
                'CUSTOMER_BENEFITS', 'ADJUSTMENT', 'Customer Benefits Distribution', (float) $row->amount, 'CREDIT'
            );

            $row->update(['status' => CustomerBenefit::STATUS_PAID]);
        });

        return true;
    }

    private function clearingMerchantId(): int
    {
        $id = Merchant::where('client_id', self::CLEARING_CLIENT_ID)->value('id');
        if (! $id) {
            throw ValidationException::withMessages(['batch_id' => ['Clearing account for Customer Benefits Distribution is not set up.']]);
        }

        return $id;
    }
}
