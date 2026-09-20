<?php

// Facturare către clienți — specs.md §12.1, plan §11. Inclus din routes/web.php, în
// grupul cu workspace (auth → session.context → workspace, ADR-014).

use App\Http\Controllers\Web\InvoiceController;
use App\Http\Controllers\Web\PaymentController;
use Illuminate\Support\Facades\Route;

Route::get('/invoices', [InvoiceController::class, 'index'])->name('invoices.index');
Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->name('invoices.show');
Route::patch('/invoices/{invoice}/send', [InvoiceController::class, 'markSent'])->name('invoices.send');
Route::patch('/invoices/{invoice}/void', [InvoiceController::class, 'void'])->name('invoices.void');
Route::get('/invoices/{invoice}/pdf', [InvoiceController::class, 'downloadPdf'])->name('invoices.pdf');
Route::patch('/invoices/{invoice}/pdf/retry', [InvoiceController::class, 'retryPdf'])->name('invoices.pdf.retry');
Route::post('/invoices/{invoice}/payments', [PaymentController::class, 'store'])->name('invoices.payments.store');

// Creare + rezumat, agățate de comandă — vezi docblock-ul `InvoiceController::forOrder()`
// pentru de ce rezumatul e un fetch JSON propriu, nu un prop pe `OrderController::show()`.
Route::post('/orders/{order}/invoices', [InvoiceController::class, 'store'])->name('orders.invoices.store');
Route::get('/orders/{order}/invoice-summary', [InvoiceController::class, 'forOrder'])->name('orders.invoice.summary');
