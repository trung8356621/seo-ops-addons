<?php

declare(strict_types=1);

/**
 * WordPress bridge API routes (owner: addons/wordpress).
 * Loaded by SeoContentAi SeoPanelProvider until peer owns Route::group bootstrap.
 */

use Omnichannel\Addons\WordPress\Http\Controllers\Api\SeoWpBridgeController;
use Illuminate\Support\Facades\Route;

Route::get('/seo-wp-bridge/ping', [SeoWpBridgeController::class, 'ping']);
Route::post('/seo-wp-bridge/push-content', [SeoWpBridgeController::class, 'pushContent']);
Route::post('/seo-wp-bridge/snapshot-callback', [SeoWpBridgeController::class, 'snapshotCallback']);
Route::post('/seo-wp-bridge/delta-event', [SeoWpBridgeController::class, 'deltaEvent']);
