<?php

use App\Http\Controllers\Api\V1\InvoiceController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/invoices', [InvoiceController::class, 'index'])->middleware('permission:cgo.invoice.view')->name('invoices.index');
    Route::get('/invoices/{invoice}', [InvoiceController::class, 'show'])->middleware('permission:cgo.invoice.view,invoice')->name('invoices.show');

    Route::post('/invoices/generate-from-billing/{billing}', [InvoiceController::class, 'generateFromBilling'])->middleware('permission:cgo.invoice.create')->name('invoices.generate');

    Route::post('/invoices/{invoice}/issue', [InvoiceController::class, 'issue'])->middleware('permission:cgo.invoice.issue,invoice')->name('invoices.issue');
    Route::post('/invoices/{invoice}/mark-paid', [InvoiceController::class, 'markPaid'])->middleware('permission:cgo.invoice.payment.record,invoice')->name('invoices.mark-paid');
    Route::post('/invoices/{invoice}/mark-partially-paid', [InvoiceController::class, 'markPartiallyPaid'])->middleware('permission:cgo.invoice.payment.record,invoice')->name('invoices.mark-partially-paid');
    Route::post('/invoices/{invoice}/void', [InvoiceController::class, 'void'])->middleware('permission:cgo.invoice.void,invoice')->name('invoices.void');
    Route::post('/invoices/{invoice}/cancel', [InvoiceController::class, 'cancel'])->middleware('permission:cgo.invoice.cancel,invoice')->name('invoices.cancel');
});
