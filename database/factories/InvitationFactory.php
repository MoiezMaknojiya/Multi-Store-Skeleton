<?php

namespace Database\Factories;

use App\Models\Invitation;
use App\Models\Role;
use App\Models\Store;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Invitation>
 */
class InvitationFactory extends Factory
{
    public function definition(): array
    {
        return [
            'store_id' => Store::factory(),
            'email' => Str::lower(fake()->unique()->safeEmail()),
            'role_id' => fn () => Role::starter(Role::STAFF)->id,
            'token_hash' => Invitation::hashToken(Str::random(64)),
            'invited_by' => null,
            'expires_at' => now()->addDays(Invitation::LIFETIME_DAYS),
        ];
    }

    /** A link a test can visit: the invitation answers to exactly this token. */
    public function withToken(string $token): static
    {
        return $this->state(fn () => ['token_hash' => Invitation::hashToken($token)]);
    }

    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }

    public function forPlatform(): static
    {
        return $this->state(fn () => ['store_id' => null]);
    }
}
