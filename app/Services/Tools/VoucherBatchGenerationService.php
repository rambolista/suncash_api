<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\Mysuncash\BatchVoucherGeneration;
use App\Models\Mysuncash\BatchVoucherGroup;
use App\Models\Mysuncash\MerchantVoucher;
use App\Models\User;
use App\Services\Notifications\Sms\SmsManager;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * "Tools > Voucher Batch Generation" (legacy `tools/voucher_batch_generation`
 * + `voucher_model::generateMerchantVoucherByBatch()`/`batchVoucherSkipUserByID()`/
 * `resendUnclaimedVoucherByBatch()`) — bulk-issues prepaid SunCash vouchers
 * (`merchant_vouchers`) from an uploaded list of recipients.
 *
 * Legacy reached this logic through two network hops (admin -> api/pos.php ->
 * services/voucher.php) purely because those were separate CodeIgniter apps;
 * the actual logic is ported directly here since it's now one app. The
 * `VOUCHER_MERCHANT_KEY` merchant-key check legacy did at the top of that hop
 * is dropped too — it was authenticating the admin-panel-as-a-client to the
 * services app, which this app's own auth/permission check already covers.
 *
 * Two legacy quirks fixed here rather than replicated:
 *  - `batch_voucher_generation.pin` was set to the whole `generatePin()`
 *    return array (encrypted_pin + pin), not a string — PHP silently stores
 *    that as the literal text "Array". Stores the real plaintext PIN instead,
 *    matching what the column (varchar(10)) and the admin list are for.
 *  - The upload parser read data starting at row 1 (no header row), while
 *    the "download sample format" file it hands out for reference HAS a
 *    header row — re-uploading that sample as a starting point fails
 *    validation on its own headers. Parsing here starts at row 2, matching
 *    the template and every other Excel import in this codebase.
 *
 * Voucher-issued notifications (Process) and the unclaimed-voucher reminder
 * (Resend) are ported using the same SmsManager/Mail path as
 * Transactions > Resend Voucher — gated the same way (simulated when the SMS
 * gateway isn't enabled in this environment, real e-mail otherwise).
 */
class VoucherBatchGenerationService
{
    private const PROCESS_LIMIT = 500;

    private const VOUCHER_LENGTH = 16;

    private const STATUS_LABELS = [
        BatchVoucherGeneration::STATUS_UNPROCESSED => 'Unprocessed',
        BatchVoucherGeneration::STATUS_PROCESSED => 'Processed',
        BatchVoucherGeneration::STATUS_SKIPPED => 'Cancelled',
    ];

    public const COLUMNS = [
        ['key' => 'name', 'label' => 'Name'],
        ['key' => 'mobile', 'label' => 'Mobile'],
        ['key' => 'amount', 'label' => 'Amount'],
        ['key' => 'email', 'label' => 'Email'],
        ['key' => 'issuer', 'label' => 'Issuer'],
        ['key' => 'issued_id', 'label' => 'Issued ID'],
        ['key' => 'voucher_number', 'label' => 'Voucher Number'],
        ['key' => 'pin', 'label' => 'Pin'],
        ['key' => 'status_label', 'label' => 'Status'],
    ];

    public function __construct(private readonly SmsManager $sms) {}

    public function listBatches(): array
    {
        $counts = BatchVoucherGeneration::query()
            ->selectRaw('batch_id, status, count(*) as total')
            ->whereNotNull('batch_id')
            ->groupBy('batch_id', 'status')
            ->get()
            ->groupBy('batch_id');

        return BatchVoucherGroup::orderByDesc('timestamp')->get()->map(function (BatchVoucherGroup $group) use ($counts) {
            $byStatus = ($counts->get($group->id) ?? collect())->pluck('total', 'status');

            return [
                'id' => $group->id,
                'batch_name' => $group->batch_name,
                'timestamp' => $group->timestamp,
                'total' => (int) $byStatus->sum(),
                'unprocessed' => (int) ($byStatus[BatchVoucherGeneration::STATUS_UNPROCESSED] ?? 0),
                'processed' => (int) ($byStatus[BatchVoucherGeneration::STATUS_PROCESSED] ?? 0),
                'skipped' => (int) ($byStatus[BatchVoucherGeneration::STATUS_SKIPPED] ?? 0),
            ];
        })->all();
    }

    public function listRows(int $batchId): array
    {
        return BatchVoucherGeneration::where('batch_id', $batchId)
            ->orderByDesc('id')
            ->get()
            ->map(fn (BatchVoucherGeneration $row) => $this->mapRow($row))
            ->all();
    }

    private function mapRow(BatchVoucherGeneration $row): array
    {
        return [
            'id' => $row->id,
            'name' => $row->name,
            'mobile' => $row->mobile,
            'amount' => (float) $row->amount,
            'email' => $row->email,
            'issuer' => $row->field1,
            'issued_id' => $row->field2,
            'voucher_number' => $row->voucher_number,
            'pin' => $row->pin,
            'status' => (int) $row->status,
            'status_label' => self::STATUS_LABELS[(int) $row->status] ?? 'Unknown',
        ];
    }

    public function exportRows(?int $batchId = null): array
    {
        $query = BatchVoucherGeneration::query();
        if ($batchId) {
            $query->where('batch_id', $batchId);
        }

        return $query->orderByDesc('id')->get()->map(fn (BatchVoucherGeneration $row) => $this->mapRow($row))->all();
    }

    public function template(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();
        $columns = ['A', 'B', 'C', 'D', 'E', 'F'];

        foreach (['Name', 'Mobile', 'Amount', 'Email', 'Issuer', 'Issued ID'] as $index => $label) {
            $sheet->setCellValue($columns[$index].'1', $label);
        }

        foreach (['John Doe', '12425551234', '50.00', 'john@example.com', 'SunCash', '12345678'] as $index => $value) {
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

        $batchId = DB::connection('mysuncash')->transaction(function () use ($rows, $file) {
            $group = BatchVoucherGroup::create(['batch_name' => $file->getClientOriginalName()]);

            BatchVoucherGeneration::insert(array_map(fn (array $row) => $row + [
                'batch_id' => $group->id,
                'status' => BatchVoucherGeneration::STATUS_UNPROCESSED,
            ], $rows));

            return $group->id;
        });

        ActivityLog::recordAction($actor, 'Tools - Voucher Batch Generation', 'imported', "Uploaded batch #{$batchId} (".count($rows).' recipients)');

        return ['batch_id' => $batchId, 'imported' => count($rows)];
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
            if ($issuedId === '' || ! is_numeric($issuedId)) {
                $rowErrors[] = "[Row {$rowNumber}] [Issued ID] is invalid.";
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
                'field1' => $issuer,
                'field2' => $issuedId,
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
     * Generates up to 500 vouchers (legacy's own per-call cap — click again
     * for the rest of a larger batch) for this batch's unprocessed rows.
     *
     * @throws ValidationException
     */
    public function processBatch(int $batchId, User $actor): array
    {
        $rows = BatchVoucherGeneration::where('batch_id', $batchId)
            ->where('status', BatchVoucherGeneration::STATUS_UNPROCESSED)
            ->limit(self::PROCESS_LIMIT)
            ->get();

        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['batch_id' => ['Nothing to process.']]);
        }

        $actorName = $actor->name ?? $actor->email;
        $processed = 0;
        $notified = 0;

        foreach ($rows as $row) {
            $voucherCode = $this->generateVoucherCode();
            $pin = $this->generatePin();
            $encryptedPin = $this->encryptPin($pin);

            DB::connection('mysuncash')->transaction(function () use ($row, $voucherCode, $pin, $encryptedPin, $actorName) {
                (new MerchantVoucher)->forceFill([
                    'voucher_code' => $voucherCode,
                    'pin' => $encryptedPin,
                    'batch_number' => '',
                    'serial_number' => '',
                    'voucher_date' => now(),
                    'amount' => $row->amount,
                    'create_date' => now(),
                    'mobile' => $row->mobile,
                    'email' => $row->email,
                    'status' => MerchantVoucher::STATUS_ACTIVE,
                    'source' => $row->field1,
                    'is_pin_encrypted' => 1,
                ])->save();

                $row->update([
                    'status' => BatchVoucherGeneration::STATUS_PROCESSED,
                    'voucher_number' => $voucherCode,
                    'pin' => $pin,
                ]);

                DB::connection('mysuncash')->table('merchant_vouchers_logs')->insert([
                    'voucher_code' => $voucherCode,
                    'action_taken' => 'PURCHASED using '.$row->field1,
                    'action_taken_by' => $actorName,
                    'timestamp' => now(),
                ]);
            });

            $processed++;
            if ($this->notifyRecipient($row->mobile, $row->email, $row->field1, $voucherCode, $pin, (float) $row->amount)) {
                $notified++;
            }
        }

        ActivityLog::recordAction($actor, 'Tools - Voucher Batch Generation', 'processed', "Processed {$processed} voucher(s) for batch #{$batchId} ({$notified} notified)");

        return ['message' => "Processed {$processed} voucher(s).", 'processed' => $processed];
    }

    public function skipRow(int $rowId, User $actor): array
    {
        $row = BatchVoucherGeneration::findOrFail($rowId);
        $row->update(['status' => BatchVoucherGeneration::STATUS_SKIPPED]);

        ActivityLog::recordAction($actor, 'Tools - Voucher Batch Generation', 'skipped', "Skipped batch voucher row #{$rowId} ({$row->name})");

        return ['message' => 'Row skipped.'];
    }

    /**
     * Re-sends the voucher code/PIN to every recipient in this batch whose
     * voucher is still unclaimed (processed but the underlying voucher is
     * still ACTIVE, i.e. not yet redeemed).
     *
     * @throws ValidationException
     */
    public function resendBatch(int $batchId, User $actor): array
    {
        $rows = BatchVoucherGeneration::where('batch_id', $batchId)
            ->where('status', BatchVoucherGeneration::STATUS_PROCESSED)
            ->whereIn('voucher_number', fn ($q) => $q->select('voucher_code')->from('merchant_vouchers')->where('status', MerchantVoucher::STATUS_ACTIVE))
            ->get();

        if ($rows->isEmpty()) {
            throw ValidationException::withMessages(['batch_id' => ['All vouchers were claimed, nothing to resend.']]);
        }

        $count = 0;
        foreach ($rows as $row) {
            if ($this->notifyRecipient($row->mobile, $row->email, $row->field1, $row->voucher_number, $row->pin, (float) $row->amount)) {
                $count++;
            }
        }

        ActivityLog::recordAction($actor, 'Tools - Voucher Batch Generation', 'resent', "Resent {$count} unclaimed voucher(s) for batch #{$batchId}");

        return ['message' => "Resend successful with {$count} count(s)."];
    }

    private function notifyRecipient(?string $mobile, ?string $email, ?string $issuedBy, string $voucherCode, string $pin, float $amount): bool
    {
        if (blank($mobile) && blank($email)) {
            return false;
        }

        $formattedAmount = number_format($amount, 2);
        $issuedByPrefix = filled($issuedBy) ? "Issued by: {$issuedBy} " : '';
        $message = "You have received a SunCash Voucher. {$issuedByPrefix}Code: {$voucherCode} PIN: {$pin} Amount: {$formattedAmount}. Secure Your Funds - DO NOT SHARE VOUCHER INFO!";

        $sentSms = filled($mobile) && ($this->sms->send($mobile, $message)['sent'] ?? false);

        $sentEmail = false;
        if (filled($email)) {
            $subject = filled($issuedBy) ? "{$issuedBy} issued SunCash Voucher" : 'SunCash Voucher';
            Mail::raw($message, fn ($mail) => $mail->to($email)->subject($subject));
            $sentEmail = true;
        }

        return $sentSms || $sentEmail;
    }

    private function generateVoucherCode(): string
    {
        do {
            $pool = date('mdhis').'0987654321'.time();
            $code = '';
            $max = strlen($pool) - 1;
            for ($i = 0; $i < self::VOUCHER_LENGTH; $i++) {
                $code .= $pool[random_int(0, $max)];
            }
        } while (MerchantVoucher::where('voucher_code', $code)->exists());

        return $code;
    }

    private function generatePin(): string
    {
        $digits = '0123456789';
        $pin = '';
        for ($i = 0; $i < 5; $i++) {
            $pin .= $digits[random_int(0, 9)];
        }

        return $pin;
    }

    private function encryptPin(string $pin): string
    {
        $key = (string) config('services.voucher.crypt_key');
        $iv = base64_decode((string) config('services.voucher.iv'));

        return base64_encode(openssl_encrypt($pin, 'aes-128-cbc', $key, OPENSSL_RAW_DATA, $iv));
    }
}
