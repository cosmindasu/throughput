<?php

// Billing & Subscription (§12.2, FR-BILL-01) — Owner-only. Inclus din routes/web.php, în
// grupul cu workspace. `settings.billing.*` e explicit exemptat de
// `App\Http\Middleware\EnsureSubscriptionAccess` (routes/web.php): un tenant `unpaid` sau
// `canceled` are nevoie EXACT de această pagină ca să iasă din blocaj.

use App\Http\Controllers\Web\Settings\BillingController;
use Illuminate\Support\Facades\Route;

Route::get('/settings/billing', [BillingController::class, 'index'])->name('settings.billing.index');

// Redirect către Stripe Customer Portal — singurul apel Stripe SINCRON, în cererea HTTP,
// din tot acest lot (abatere conștientă de la ADR-013, vezi docblock-ul controllerului).
Route::post('/settings/billing/portal', [BillingController::class, 'portal'])->name('settings.billing.portal');
