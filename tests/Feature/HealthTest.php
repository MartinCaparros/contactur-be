<?php

namespace Tests\Feature;

use Tests\TestCase;

class HealthTest extends TestCase
{
    public function test_health_endpoint_returns_ok(): void
    {
        $this->getJson('/api/v1/health')
            ->assertOk()
            ->assertExactJson(['status' => 'ok']);
    }
}
