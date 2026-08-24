<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected static ?string $password;

    public function definition(): array
    {
        return [
            'account_number' => 'CUST-' . now()->format('Ymd') . '-' . strtoupper(Str::random(4)),
            'name' => fake()->name(),
            'first_name' => fake()->firstName(),
            'middle_name' => fake()->optional()->firstName(),
            'last_name' => fake()->lastName(),
            'email' => fake()->unique()->safeEmail(),
            'password' => static::$password ??= Hash::make('password'),
            'mobile_number' => fake()->optional()->phoneNumber(),
            'address' => fake()->optional()->address(),
            'status' => 'active',
            'theme_preference' => null,
        ];
    }
}
