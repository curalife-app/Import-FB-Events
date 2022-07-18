<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\FacebookEventImportController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider within a group which
| is assigned the "api" middleware group. Enjoy building your API!
|
*/


//Route::get('orders', [FacebookEventImportController::class, 'getGlobalOrders']);
//Route::get('bulk', [FacebookEventImportController::class, 'sendBulkEvents']);

Route::middleware('route.validate')->group(function () {
    Route::get('{store}/webhooks/', [FacebookEventImportController::class, 'getShopifyWebhooks']);
    Route::get('{store}/webhooks/create', [FacebookEventImportController::class, 'addShopifyPaidOrderWebhook']);
    Route::get('{store}/webhooks/delete/{id}', [FacebookEventImportController::class, 'removeShopifyPaidOrderWebhook']);
    //Route::get('{store}/webhooks/order', [FacebookEventImportController::class, 'listenShopifyOrderWebhook']);
    Route::post('{store}/webhooks/order', [FacebookEventImportController::class, 'listenShopifyOrderWebhook']);
});

Route::middleware('auth:api')->get('/user', function (Request $request) {
    return $request->user();
});
