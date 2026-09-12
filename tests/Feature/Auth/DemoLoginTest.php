<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * FR-PUB-02, BR-PUB-01 — autentificarea „un click" pe conturile demo funcționează
 * DOAR când `DEMO_MODE=true` (citit din config).
 *
 * Fără `RefreshDatabase` (`tests/TestCase.php` nu are încă un setup de migrare pentru
 * teste — vezi raportul agentului): contul demo se creează cu `firstOrCreate`, ca să nu
 * intre în conflict cu seed-ul real de conturi demo dacă a rulat deja, și se șterge la
 * final DOAR dacă a fost creat de acest test (`wasRecentlyCreated`), ca să nu lase
 * urme permanente în baza de test partajată.
 */
beforeEach(function () {
    $this->demoOwner = User::query()->firstOrCreate(
        ['email' => 'demo.owner@throughput.dev'],
        ['name' => 'Demo Owner (test fixture)', 'password' => Hash::make(Str::random(32))]
    );

    $this->demoOwnerCreatedByTest = $this->demoOwner->wasRecentlyCreated;
});

afterEach(function () {
    if ($this->demoOwnerCreatedByTest) {
        $this->demoOwner->delete();
    }
});

it('logs the demo user in instantly when DEMO_MODE is enabled', function () {
    config(['throughput.demo.mode' => true]);

    $response = $this->post('/login/demo/owner');

    $response->assertRedirect();
    $this->assertAuthenticatedAs($this->demoOwner);
});

it('returns 404 for the demo login route when DEMO_MODE is disabled', function () {
    config(['throughput.demo.mode' => false]);

    $response = $this->post('/login/demo/owner');

    $response->assertNotFound();
    $this->assertGuest();
});

it('does not accept a role outside owner|manager|agent|viewer', function () {
    config(['throughput.demo.mode' => true]);

    $response = $this->post('/login/demo/superadmin');

    $response->assertNotFound();
    $this->assertGuest();
});
