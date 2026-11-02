<?php

declare(strict_types=1);

use Workbench\App\Models\User;

it('sends an unusable envelope as application/problem+json', function (): void {
    $this->actingAs(new User(['name' => 'admin']))
        ->postJson('/laraql', ['queries' => []])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://github.com/medyat/laraql/blob/production/docs/protocol.md#invalid_request');
});
