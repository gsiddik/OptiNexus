<?php

use App\Http\Controllers\Api\V1\CommercialPortalController;
use App\Http\Controllers\Api\V1\InvoiceController;
use App\Http\Controllers\Api\V1\InvoicePaymentController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth:sanctum', 'ecosystem'])->group(function () {
    Route::get('/invoices', [InvoiceController::class, 'index'])->middleware('permission:cgo.invoice.view')->name('invoices.index');
    Route::get('/invoices/{invoice}/download', [InvoiceController::class, 'download'])->middleware('permission:cgo.invoice.view,invoice')->name('invoices.download');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('permission:cgo.invoice.view,invoice')->name('invoices.show');

    Route::post('/invoices/generate-from-billing/{billing}', [InvoiceController::class, 'generateFromBilling'])->middleware('permission:cgo.invoice.create')->name('invoices.generate');

    Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->middleware('permission:cgo.invoice.issue,invoice')->name('invoices.issue');
    Route::post('/invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid'])->middleware('permission:cgo.invoice.payment.record,invoice')->name('invoices.mark-paid');
    Route::post('/invoices/{invoice}/mark-partially-paid', [InvoiceController::class, 'markPartiallyPaid'])->middleware('permission:cgo.invoice.payment.record,invoice')->name('invoices.mark-partially-paid');
    Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])->middleware('permission:cgo.invoice.void,invoice')->name('invoices.void');
    Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->middleware('permission:cgo.invoice.cancel,invoice')->name('invoices.cancel');
});

Route::prefix('invoice-payments/{payment}')->middleware(['auth:sanctum', 'ecosystem'])->group(function () {
    Route::get('/', [InvoicePaymentController::class, 'show'])->middleware('permission:cgo.invoice.view,payment');
    Route::get('/proof', [InvoicePaymentController::class, 'proof'])->middleware('permission:cgo.invoice.view,payment');
    Route::post('/verify', [InvoicePaymentController::class, 'verify'])->middleware('permission:cgo.invoice.payment.record,payment');
    Route::post('/reject', [InvoicePaymentController::class, 'reject'])->middleware('permission:cgo.invoice.payment.record,payment');
});

Route::prefix('commercial-portal/tenants/{tenant}')->middleware('service_account:commercial.read')->group(function () {
    $controller = CommercialPortalController::class;
    Route::get('/invoices', [$controller, 'invoiceIndex']);
    Route::get('/invoices/{invoice}', [$controller, 'invoiceShow']);
    Route::get('/payments', [$controller, 'paymentIndex']);
    Route::get('/payments/{payment}', [$controller, 'paymentShow']);
    Route::get('/payments/{payment}/proof', [$controller, 'proof']);
    Route::post('/payments', [$controller, 'submit'])->middleware('service_account:commercial.payment.submit');
    Route::post('/payments/{payment}/proof', [$controller, 'attachProof'])->middleware('service_account:commercial.payment.submit');
});
