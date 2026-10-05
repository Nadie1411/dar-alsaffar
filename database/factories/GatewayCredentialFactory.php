<?php

namespace Database\Factories;

use App\Models\GatewayCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<GatewayCredential>
 */
class GatewayCredentialFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'provider' => GatewayCredential::MYFATOORAH,
            'api_key' => 'panel-saved-api-key-not-a-real-secret',
            'webhook_secret' => 'panel-saved-webhook-secret',
            'api_url' => null,
            'enabled' => null,
            'updated_by' => null,
        ];
    }

    /** Keys saved in the panel, pointing at the sandbox. */
    public function sandbox(): static
    {
        return $this->state(['api_url' => 'https://apitest.myfatoorah.com']);
    }

    public function switchedOff(): static
    {
        return $this->state(['enabled' => false]);
    }
}
