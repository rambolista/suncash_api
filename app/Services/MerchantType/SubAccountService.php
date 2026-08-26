<?php

namespace App\Services\MerchantType;

use App\Models\Mysuncash\Customer;
use App\Models\Mysuncash\EzkardAccount;
use App\Models\Mysuncash\Merchant;
use App\Models\Mysuncash\ServicesPermission;
use App\Models\Mysuncash\SubAccount;
use App\Models\Mysuncash\SubAccountSetting;
use App\Models\Mysuncash\WebLog;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * Business Management's "Sub Account" button — bulk-imports a school/
 * charity-type merchant's students as sub-accounts from an uploaded Excel
 * file. Mirrors legacy's upload_sub_account()/parse_sub_account_file()/
 * Clients_model::process_sub_account(): gated on the merchant having the
 * SUBACCOUNT service permission granted, then one `customers` + one
 * placeholder `ezkard_accounts` + one `sub_accounts` + one
 * `sub_account_settings` row per imported student.
 */
class SubAccountService
{
    private const SERVICE_CODE = 'SUBACCOUNT';

    /** Column letter => [legacy label, row field name]. A-F are required; G-R are optional parent contacts. */
    private const COLUMNS = [
        'A' => ['Student ID', 'student_id_number'],
        'B' => ['First Name', 'first_name'],
        'C' => ['Last Name', 'last_name'],
        'D' => ['Gender', 'gender'],
        'E' => ['Date of Birth', 'birthday'],
        'F' => ['Address', 'address1'],
        'G' => ['Parent 1 Email', 'parent_1_email'],
        'H' => ['Parent 1 Phone', 'parent_1_phone'],
        'I' => ['Parent 1 Name', 'parent_1_name'],
        'J' => ['Parent 2 Email', 'parent_2_email'],
        'K' => ['Parent 2 Phone', 'parent_2_phone'],
        'L' => ['Parent 2 Name', 'parent_2_name'],
        'M' => ['Parent 3 Email', 'parent_3_email'],
        'N' => ['Parent 3 Phone', 'parent_3_phone'],
        'O' => ['Parent 3 Name', 'parent_3_name'],
        'P' => ['Parent 4 Email', 'parent_4_email'],
        'Q' => ['Parent 4 Phone', 'parent_4_phone'],
        'R' => ['Parent 4 Name', 'parent_4_name'],
    ];

    private const REQUIRED_COLUMNS = ['A', 'B', 'C', 'D', 'E', 'F'];

    private const PHONE_COLUMNS = ['H', 'K', 'N', 'Q'];

    private const CUSTOMER_FIELDS = ['first_name', 'last_name', 'gender', 'birthday', 'address1'];

    private const SUB_ACCOUNT_FIELDS = [
        'student_id_number',
        'parent_1_email', 'parent_1_phone', 'parent_1_name',
        'parent_2_email', 'parent_2_phone', 'parent_2_name',
        'parent_3_email', 'parent_3_phone', 'parent_3_name',
        'parent_4_email', 'parent_4_phone', 'parent_4_name',
    ];

    /**
     * @throws ValidationException
     */
    private function findBusinessOrFail(int $merchantId): Merchant
    {
        $merchant = Merchant::where('merchant_type_id', Merchant::MERCHANT_TYPE_BUSINESS)->find($merchantId);

        if (! $merchant) {
            throw ValidationException::withMessages(['id' => ['Business not found.']]);
        }

        return $merchant;
    }

    /**
     * @throws ValidationException
     */
    public function assertPermissionGranted(int $merchantId): void
    {
        $granted = ServicesPermission::where('client_record_id', $merchantId)
            ->where('status', 'A')
            ->whereHas('systemService', fn (Builder $query) => $query->where('code', self::SERVICE_CODE)->where('status', 'A'))
            ->exists();

        if (! $granted) {
            throw ValidationException::withMessages(['permission' => ['Sub Account permission not granted.']]);
        }
    }

    public function template(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet;
        $sheet = $spreadsheet->getActiveSheet();

        foreach (self::COLUMNS as $column => $definition) {
            $sheet->setCellValue($column.'1', $definition[0]);
        }

        $sample = [
            'A' => 'ID-123', 'B' => 'John', 'C' => 'Doe', 'D' => 'Male', 'E' => '1970-01-01',
            'F' => '000 Evergreen Terrace, Springfield, United States',
            'G' => 'parent1@email.com', 'H' => '12425551234', 'I' => 'Jane Doe',
        ];
        foreach ($sample as $column => $value) {
            $sheet->setCellValue($column.'2', $value);
        }
        $sheet->getStyle('E2')->getNumberFormat()->setFormatCode('yyyy-mm-dd');

        return $spreadsheet;
    }

    public function writeTemplateTo(string $path): void
    {
        (new Xlsx($this->template()))->save($path);
    }

    /**
     * @throws ValidationException
     */
    public function import(int $merchantId, UploadedFile $file, string $actorId): array
    {
        $merchant = $this->findBusinessOrFail($merchantId);
        $this->assertPermissionGranted($merchantId);

        $extension = strtoupper($file->getClientOriginalExtension());
        if (! in_array($extension, ['XLS', 'XLSX'], true)) {
            throw ValidationException::withMessages(['file' => ['Please upload an .xlsx or .xls file.']]);
        }

        try {
            $spreadsheet = IOFactory::load($file->getRealPath());
        } catch (\Throwable $exception) {
            throw ValidationException::withMessages(['file' => ['Unable to read this file: '.$exception->getMessage()]]);
        }

        $rows = $this->parseRows($spreadsheet->getActiveSheet());

        return DB::connection('mysuncash')->transaction(function () use ($rows, $merchant, $actorId) {
            $imported = 0;
            $skipped = 0;

            foreach ($rows as $row) {
                $exists = SubAccount::where('client_id', $merchant->id)
                    ->where('student_id_number', $row['student_id_number'])
                    ->exists();

                if ($exists) {
                    $skipped++;

                    continue;
                }

                $this->importRow($merchant->id, $row);
                $imported++;
            }

            WebLog::create([
                'merchant_id' => $merchant->id,
                'customer_id' => -1,
                'user_id' => $actorId,
                'updated_by' => $actorId,
                'log_type' => 'IMPORT_SUBACCOUNT_FILES',
                'data' => "client id: {$merchant->id}",
            ]);

            return ['imported' => $imported, 'skipped' => $skipped];
        });
    }

    private function importRow(int $merchantId, array $row): void
    {
        $customer = Customer::create(array_merge(
            array_intersect_key($row, array_flip(self::CUSTOMER_FIELDS)),
            [
                'is_sub_account' => 1,
                'is_new' => 1,
                'status' => 'A',
                'country' => 'Bahamas',
            ],
        ));

        $ezkard = EzkardAccount::create([
            'card_ref_number' => '',
            'card_prefix' => '',
            'card_number' => (string) $customer->id,
            'expiry_date' => '',
            'cvv_code' => '',
            'card_type_id' => -1,
            'card_balance' => 0,
            'mobile_number' => '',
            'card_status_id' => 0,
            'client_id' => (string) $merchantId,
            'rawcard' => '',
        ]);

        $customer->update(['ezkard_account_id' => $ezkard->id]);

        SubAccount::create(array_merge(
            array_intersect_key($row, array_flip(self::SUB_ACCOUNT_FIELDS)),
            [
                'client_id' => $merchantId,
                'sub_customer_id' => $customer->id,
            ],
        ));

        SubAccountSetting::create(['customer_id' => $customer->id]);
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
            $rowIsEmpty = true;
            $rowErrors = [];
            $row = [];

            foreach (self::COLUMNS as $column => [$label, $field]) {
                $value = $sheet->getCell($column.$rowNumber)->getValue();
                $value = $value !== null ? trim((string) $value) : '';

                if ($value !== '') {
                    $rowIsEmpty = false;
                }

                if ($column === 'E' && $value !== '' && is_numeric($value)) {
                    $value = $this->excelSerialToDate((float) $value);
                }

                if (in_array($column, self::PHONE_COLUMNS, true) && $value !== '') {
                    $value = preg_replace('/[^a-zA-Z0-9]/', '', $value);
                }

                if (in_array($column, self::REQUIRED_COLUMNS, true) && $value === '') {
                    $rowErrors[] = "[Row {$rowNumber}] [{$label}] is required.";
                }

                $row[$field] = $value;
            }

            if ($rowIsEmpty) {
                continue;
            }

            $hasAtLeastOneRow = true;

            if ($rowErrors) {
                $errors = array_merge($errors, $rowErrors);

                continue;
            }

            if (! empty($row['birthday'])) {
                $row['birthday'] = $this->normalizeBirthday($row['birthday']);
            }

            $rows[] = $row;
        }

        if (! $hasAtLeastOneRow) {
            throw ValidationException::withMessages(['file' => ['No data found.']]);
        }

        if ($errors) {
            throw ValidationException::withMessages(['rows' => $errors]);
        }

        return $rows;
    }

    private function excelSerialToDate(float $serial): string
    {
        $origin = new \DateTime('1899-12-30');
        $days = floor($serial);
        $fraction = $serial - $days;
        $origin->modify("+{$days} days");
        $origin->modify('+'.round($fraction * 86400).' seconds');

        return $origin->format('Y-m-d');
    }

    private function normalizeBirthday(string $value): string
    {
        $date = \DateTime::createFromFormat('d/m/Y', $value);
        if ($date) {
            return $date->format('Y-m-d');
        }

        $timestamp = strtotime($value);

        return $timestamp !== false ? date('Y-m-d', $timestamp) : $value;
    }
}
