<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Maximum requests to make to Shopify in one call
    |--------------------------------------------------------------------------
    |
    | Inversely proportionate to the limit of items returned in requests
    | The more items are returned in one request - the less requests the system needs to make
    |
    */

    'request_limit_shopify' => 100,

    /*
    |--------------------------------------------------------------------------
    | Number of items to return in one Shopify request
    |--------------------------------------------------------------------------
    |
    | Define how many items to receive in one Shopify request.
    |
    | Maximum number of items allowed by Shopify: 1000
    |
    */

    'order_limit_shopify' => 250,

    /*
    |--------------------------------------------------------------------------
    | Facebook Import Mode
    |--------------------------------------------------------------------------
    |
    | The implementation supports 2 modes for importing events into facebook:
    | * Batch (recommended) - events are split into batches (up to 1000) and sent together in a batch.
    |   This mode only needs to send a request per one batch, but if one event has incompatible data, the whole batch will not go through.
    | * Async - event are sent one by one asynchronously, if one event fails, all other events will not be affected, but more requests are made
    |
    | The batch mode is recommended for import, but if for some reason the batch import is failing, async mode might be able to process it.
    |
    | Supported: "batch", "async"
    |
    */

    'import_mode_facebook' => 'batch', //Options: batch, async

    /*
    |--------------------------------------------------------------------------
    | Limit of Facebook events per batch
    |--------------------------------------------------------------------------
    |
    | Define how many events to send in one batch.
    | Events will be split into necessary number of batches to be able send all of them.
    |
    | Maximum number of events per batch allowed by Facebook: 1000
    |
    */

    'event_batch_limit_facebook' => 1000,

    /*
    |--------------------------------------------------------------------------
    | String for oldest allowed event time to import
    |--------------------------------------------------------------------------
    |
    | Facebook allows event times to go up to 7 days before the request time.
    | The time string as 1 minute buffer for potential delays.
    |
    | Maximum oldest event time string: -7 days
    |
    */

    'oldest_event_time_string' => '-7 days +1 minute',


];
