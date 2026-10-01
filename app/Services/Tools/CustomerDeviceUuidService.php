<?php

namespace App\Services\Tools;

use App\Models\ActivityLog;
use App\Models\Mysuncash\CustomerDevice;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * "Tools > Customer Device UUID" (legacy `customers_uuid` controller +
 * `customers_uuid_model::getAllCustomersUuid()`/`delete_customer_status()`).
 * Lists each registered device (`customers_uuid`) alongside the IP/country
 * of its most recent successful login, and lets an admin unlink one.
 *
 * Legacy's listing query is genuinely buggy SQL: it LEFT JOINs a
 * `GROUP BY id DESC` subquery of the whole customer_login_logs table (a
 * no-op grouping, since `id` is the primary key) and then filters with a
 * plain `cll.status = 'SUCCESS'`, which turns the LEFT JOIN into an
 * effective INNER JOIN against an arbitrary, non-deterministic login row.
 * Replicated here with the same *visible* behavior (one row per uuid, only
 * devices with at least one successful login) but deterministically: the
 * device's own most recent successful login row.
 */
class CustomerDeviceUuidService
{
    public const COLUMNS = [
        ['key' => 'timestamp', 'label' => 'Timestamp'],
        ['key' => 'customer_name', 'label' => 'Customer Name'],
        ['key' => 'uuid', 'label' => 'Customers Uuid'],
        ['key' => 'model', 'label' => 'Model'],
        ['key' => 'ip_address', 'label' => 'IP Address'],
        ['key' => 'country', 'label' => 'Country'],
    ];

    public function list(?string $dateFrom, ?string $dateTo): array
    {
        $latestSuccessLogins = DB::connection('mysuncash')->table('customer_login_logs')
            ->select('uuid', DB::raw('MAX(id) as max_id'))
            ->where('status', 'SUCCESS')
            ->groupBy('uuid');

        $rows = DB::connection('mysuncash')->table('customers_uuid as cu')
            ->leftJoin('customers as c', 'c.id', '=', 'cu.customer_id')
            ->joinSub($latestSuccessLogins, 'latest', 'latest.uuid', '=', 'cu.uuid')
            ->join('customer_login_logs as cll', 'cll.id', '=', 'latest.max_id')
            ->when($dateFrom, fn ($query) => $query->where('cu.timestamp', '>=', Carbon::parse($dateFrom)->startOfDay()))
            ->when($dateTo, fn ($query) => $query->where('cu.timestamp', '<', Carbon::parse($dateTo)->addDay()->startOfDay()))
            ->selectRaw("cu.id, cu.customer_id, cu.uuid, cu.model, cu.timestamp, CONCAT(c.first_name, ' ', c.last_name) as customer_name, cll.ip_address, cll.country")
            ->orderByDesc('cu.id')
            ->get();

        return $rows->map(fn ($row) => [
            'id' => $row->id,
            'customer_id' => $row->customer_id,
            'timestamp' => $row->timestamp,
            'customer_name' => $row->customer_name,
            'uuid' => $row->uuid,
            'model' => $row->model,
            'ip_address' => $row->ip_address,
            'country' => $row->country,
        ])->all();
    }

    /** @throws ValidationException */
    public function delete(int $id, User $actor, Request $request): void
    {
        $device = CustomerDevice::find($id);
        if (! $device) {
            throw ValidationException::withMessages(['id' => ['Customer Uuid not found.']]);
        }

        $before = $device->getAttributes();
        $device->delete();

        ActivityLog::recordDeleted($actor, 'Tools - Customer Device UUID', $device, $before, ['uuid', 'customer_id', 'model'], $request, "Unlinked device {$device->uuid} from customer #{$device->customer_id}");
    }
}
