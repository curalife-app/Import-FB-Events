@extends('platform::dashboard')

@section('title','LIVE Event Tracking')
@section('description', 'Send events to Facebook via Conversion API by using Shopify order webhooks.')

@section('navbar')
    <!--div class="text-center">
        Reset
    </div-->
@stop

@section('content')

    <div class="row">
        <div class="col-md">
            <fieldset class="mb-3" data-async="">

                <div class="col p-0 px-3">
                    <legend class="text-black">
                        Toggle Shopify webhooks ON or OFF
                    </legend>
                </div>

                <div class="bg-white rounded shadow-sm p-4 py-4 d-flex flex-column">
                    <input id="csrf" name="csrf" value="{{ csrf_token() }}" type="hidden" class="hidden">

                    <p class="small mt-2 mb-0">
                        Activate or deactivate <b>LIVE Facebook Event Tracking</b> triggered via Shopify paid order webhooks.
                        <br>
                        Webhooks are captured by the server where they are converted to FB events and sent to Facebook as soon the order comes in.
                        <br>
                        Event ID is set using Shopify order ID to allow deduplication.
                    </p>
                    <p class="small mt-2 mb-2">
                        <i>Note: Shopify requires SSL to be configured to be able to add webhooks.</i>
                    </p>
                    @if (parse_url(config('app.url'), PHP_URL_SCHEME) != 'https')
                    <p class="small mt-2 mb-4 error">
                        SSL not configured. Webhooks are disabled.
                    </p>
                    @endif

@foreach($activeWebhooks as $store => $webhook)

        <div class="form-group">
            <label for="field-free-switch-ec71425b52b57dec421cef119aaa21bfb2789a0c" class="form-label">
                Events on Shopify {{ strtoupper($store) }} store
            </label>

            <div class="d-flex flex-row">
                <input hidden="" name="webhooks-toggle-{{ $store }}" value="0">
                <div class="form-check form-switch">
                    <input value="1" type="checkbox" class="form-check-input bigger-toggle webhooks-toggle"
                           novalue="0" yesvalue="1" name="webhooks-toggle-{{ $store }}"
                           data-store="{{ $store }}"
                           title="Free switch" placeholder="Event for free"
                           id="field-webhooks-toggle"
                           @if ($webhook) checked="checked" @endif
                           @if (parse_url(config('app.url'), PHP_URL_SCHEME) != 'https') disabled="disabled" @endif
                    >
                    <!--label class="form-check-label" for="field-free-switch-ec71425b52b57dec421cef119aaa21bfb2789a0c">Event for free</label-->
                </div>
                <div class="webhook-status webhook-status-{{ $store }}">@if ($webhook) Active @else Inactive @endif</div>

            </div>

            <!--small class="form-text text-muted">Event for free</small-->
        </div>
@endforeach

                </div>
            </fieldset>
        </div>
    </div>
@stop
