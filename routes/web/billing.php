<?php

// Billing & Subscription (§12.2, FR-BILL-01) — Owner-only. Inclus din routes/web.php, în
// grupul cu workspace. `settings.billing.*` e explicit exemptat de
// `App\Http\Middleware\EnsureSubscriptionAccess` (routes/web.php): un tenant `unpaid` sau
// `canceled` are nevoie EXACT de această pagină ca să iasă din blocaj.

use App\Http\Controllers\Web\Settings\BillingController;
use App\Http\Controllers\Webhooks\WebhookHealthController;
use Illuminate\Support\Facades\Route;

Route::get('/settings/billing', [BillingController::class, 'index'])->name('settings.billing.index');

// §25.2 + §12.3 — ecranul de operare care face vizibile evenimentele Stripe (`failed` cu
// motiv, și `ignored`: sandbox partajat cu alt proiect, decizia proprietarului din
// 2026-09-20). Stă în acest fișier, nu în `routes/web/settings.php`, fiindcă acela aparține
// altui lot din acest val; suprafața de date e oricum aceeași cu a paginii de billing
// (evenimente de abonament Stripe), deci și dreptul e același — `billing.view`, Owner-only.
Route::get('/settings/webhooks', [WebhookHealthController::class, 'index'])->name('settings.webhooks.index');

// Redirect către Stripe Customer Portal — singurul apel Stripe SINCRON, în cererea HTTP,
// din tot acest lot (abatere conștientă de la ADR-013, vezi docblock-ul controllerului).
Route::post('/settings/billing/portal', [BillingController::class, 'portal'])->name('settings.billing.portal');
