<?php

namespace App\Services\Promotions;

use App\Models\Mysuncash\GeoPromo;
use Illuminate\Validation\ValidationException;

/**
 * Geo-fenced Sign Up Promotion zones — admins draw a polygon on a map;
 * new customers who sign up with a location inside an active zone get
 * `promo_amount` credited automatically (that credit happens in the
 * customer-facing services app, not here — this is the zone editor).
 */
class GeoPromoService
{
    public function list(): array
    {
        return GeoPromo::where('status', GeoPromo::STATUS_ACTIVE)
            ->orderByDesc('id')
            ->get()
            ->map(fn (GeoPromo $promo) => $this->present($promo))
            ->all();
    }

    private function present(GeoPromo $promo): array
    {
        return [
            'id' => $promo->id,
            'promo_description' => $promo->promo_description,
            'promo_amount' => $promo->promo_amount,
            'promo_country' => $promo->promo_country,
            'date_from' => $promo->date_from,
            'date_to' => $promo->date_to,
            'coordinates' => json_decode((string) $promo->coordinates, true) ?: [],
            'create_date' => $promo->create_date,
        ];
    }

    /**
     * @throws ValidationException
     */
    public function find(int $id): array
    {
        $promo = GeoPromo::where('status', GeoPromo::STATUS_ACTIVE)->find($id);
        if (! $promo) {
            throw ValidationException::withMessages(['id' => ['Geo promo zone not found.']]);
        }

        return $this->present($promo);
    }

    private function validate(array $data): array
    {
        $errors = [];

        $amount = $data['promo_amount'] ?? null;
        if (! is_numeric($amount) || (float) $amount <= 0) {
            $errors['promo_amount'] = ['Enter a valid bonus amount.'];
        }

        if (! filled($data['promo_description'] ?? null)) {
            $errors['promo_description'] = ['Description is required.'];
        }

        if (! filled($data['promo_country'] ?? null)) {
            $errors['promo_country'] = ['Select a country.'];
        }

        $dateFrom = $data['date_from'] ?? null;
        $dateTo = $data['date_to'] ?? null;
        if (! filled($dateFrom)) {
            $errors['date_from'] = ['Start date is required.'];
        }
        if (! filled($dateTo)) {
            $errors['date_to'] = ['End date is required.'];
        }
        if (filled($dateFrom) && filled($dateTo) && strtotime((string) $dateTo) < strtotime((string) $dateFrom)) {
            $errors['date_to'] = ['End date must be on or after the start date.'];
        }

        $coordinates = $data['coordinates'] ?? null;
        if (! is_array($coordinates) || count($coordinates) < 3) {
            $errors['coordinates'] = ['Draw a zone with at least 3 points on the map.'];
        } else {
            foreach ($coordinates as $point) {
                if (! isset($point['lat'], $point['lng']) || ! is_numeric($point['lat']) || ! is_numeric($point['lng'])) {
                    $errors['coordinates'] = ['The drawn zone has invalid points.'];
                    break;
                }
            }
        }

        if ($errors) {
            throw ValidationException::withMessages($errors);
        }

        return $data;
    }

    /**
     * @throws ValidationException
     */
    public function create(array $data, string $actorName): GeoPromo
    {
        $this->validate($data);

        return GeoPromo::create([
            'promo_type' => 'Signup Promotion',
            'promo_amount' => (float) $data['promo_amount'],
            'promo_description' => $data['promo_description'],
            'promo_country' => $data['promo_country'],
            'create_date' => now(),
            'updated_by' => $actorName,
            'date_from' => $data['date_from'],
            'date_to' => $data['date_to'],
            'coordinates' => json_encode(array_map(
                fn ($point) => ['lat' => (float) $point['lat'], 'lng' => (float) $point['lng']],
                $data['coordinates']
            )),
            'status' => GeoPromo::STATUS_ACTIVE,
        ]);
    }

    /**
     * @throws ValidationException
     */
    public function update(int $id, array $data, string $actorName): GeoPromo
    {
        $promo = GeoPromo::where('status', GeoPromo::STATUS_ACTIVE)->find($id);
        if (! $promo) {
            throw ValidationException::withMessages(['id' => ['Geo promo zone not found.']]);
        }

        $this->validate($data);

        $promo->update([
            'promo_amount' => (float) $data['promo_amount'],
            'promo_description' => $data['promo_description'],
            'promo_country' => $data['promo_country'],
            'update_date' => now(),
            'updated_by' => $actorName,
            'date_from' => $data['date_from'],
            'date_to' => $data['date_to'],
            'coordinates' => json_encode(array_map(
                fn ($point) => ['lat' => (float) $point['lat'], 'lng' => (float) $point['lng']],
                $data['coordinates']
            )),
        ]);

        return $promo->fresh();
    }

    /**
     * @throws ValidationException
     */
    public function delete(int $id, string $actorName): void
    {
        $promo = GeoPromo::where('status', GeoPromo::STATUS_ACTIVE)->find($id);
        if (! $promo) {
            throw ValidationException::withMessages(['id' => ['Geo promo zone not found.']]);
        }

        $promo->update(['status' => GeoPromo::STATUS_DELETED, 'update_date' => now(), 'updated_by' => $actorName]);
    }
}
