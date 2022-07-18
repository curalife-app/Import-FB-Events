<?php

return [

    'request_limit_shopify' => 100,
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



    'oldest_event_time_string' => '-7 days +1 minute',


];
