@extends('platform::dashboard')

@section('title','FB Event Import from Shopify API')
@section('description', 'Import events into Facebook for all orders within a certain date range.')

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
                        API Import Setup
                    </legend>
                </div>

                <div class="bg-white rounded shadow-sm p-4 py-4 d-flex flex-column">

                    <div class="form-group">
                        <label for="radiobutton" class="text-wrap mt-2 form-label">
                            Predefined date range
                        </label>

                        <div data-controller="radiobutton">
                            <div class="btn-group btn-group-toggle p-0" data-toggle="buttons">

                                <label class="btn btn-default active" data-action="click->radiobutton#checked" checked="checked">
                                    <input type="radio" class="btn-check active" name="import-template-date-range" value="168" id="import-template-date-range-7-days" checked="checked">Last 7 days</label>
                                <label class="btn btn-default" data-action="click->radiobutton#checked" checked="checked">
                                    <input type="radio" class="btn-check active" name="import-template-date-range" value="72" id="import-template-date-range-3-days">Last 3 days</label>
                                <label class="btn btn-default" data-action="click->radiobutton#checked" checked="checked">
                                    <input type="radio" class="btn-check active" name="import-template-date-range" value="24" id="import-template-date-range-24-hours">Last 24 hours</label>
                                <label class="btn btn-default" data-action="click->radiobutton#checked" checked="checked">
                                    <input type="radio" class="btn-check active" name="import-template-date-range" value="1" id="import-template-date-range-1-hour">Last 1 hour</label>
                                <label class="btn btn-default" data-action="click->radiobutton#checked" checked="checked">
                                    <input type="radio" class="btn-check active" name="import-template-date-range" value="0" id="import-template-date-range-custom">Custom</label>
                            </div>
                        </div>

                        <small class="form-text text-muted">Date range inputs are locked for predefined periods</small>
                    </div>


                    <div class="form-group row-cols-sm-2">
                        <label for="created_at_max" class="text-wrap mt-2 form-label">
                            Import orders from (UTC)
                        </label>

                        <div class="col">
                            <div data-controller="input" data-input-mask="">
                                <input class="form-control" name="created_at_min" type="datetime-local" title="Import orders from" value="{{ date('Y-m-d\TH:i:s', strtotime(config('constants.oldest_event_time_string'))) }}" id="created_at_min">
                            </div>

                        </div>
                    </div>

                    <div class="form-group row-cols-sm-2">
                        <label for="created_at_max" class="text-wrap mt-2 form-label">
                            Import orders to (UTC)
                        </label>

                        <div class="col">
                            <div data-controller="input" data-input-mask="">
                                <input class="form-control" name="created_at_max" type="datetime-local" title="Import orders to" value="{{ date('Y-m-d\TH:i:s') }}" id="created_at_max">
                            </div>

                        </div>
                    </div>

                    <div class="form-group">
                        <label for="field-radio-import-mode" class="form-label">Import mode

                            <sup class="text-black" role="button"
                                 data-controller="popover"
                                 data-action="click->popover#trigger"
                                 data-container="body"
                                 data-toggle="popover"
                                 tabindex="0"
                                 data-trigger="focus"
                                 data-placement="auto"
                                 data-delay-show="300"
                                 data-delay-hide="200"
                                 data-bs-content="Batch mode is recommended for import, but if a batch contains an event with faulty data blocking the import, async mode might be able to process it." data-bs-original-title="" title="">
                                <svg xmlns="http://www.w3.org/2000/svg" version="1.1" width="1em" height="1em" viewBox="0 0 32 32" role="img" fill="currentColor" componentname="orchid-icon">
                                    <path d="M15 21.063v-15.063c0-0.563 0.438-1 1-1s1 0.438 1 1v15.063h-2zM15 23.031h2v1.875h-2v-1.875zM0 16c0-8.844 7.156-16 16-16s16 7.156 16 16-7.156 16-16 16-16-7.156-16-16zM30.031 16c0-7.719-6.313-14-14.031-14s-14 6.281-14 14 6.281 14 14 14 14.031-6.281 14.031-14z"></path>
                                </svg>
                            </sup>
                        </label>

                        <div class="form-check">
                            <input id="import-mode-batch-radio" type="radio" class="form-check-input" value="batch" name="import-mode" placeholder="Batch" title="Import mode" checked="checked">
                            <label class="form-check-label" for="import-mode-batch-radio">Batch</label>
                        </div>

                        <div class="form-check">
                            <input id="import-mode-async-radio" type="radio" class="form-check-input" value="async" name="import-mode" placeholder="Async">
                            <label class="form-check-label" for="import-mode-async-radio">Async</label>
                        </div>

                    </div>

                    <div class="form-group mb-0 mt-5 d-flex flex-row">

                        <input id="csrf" name="csrf" value="{{ csrf_token() }}" type="hidden" class="hidden">
                        <button id="upload-events-api-btn" data-controller="button" data-turbo="true" class="btn btn-info">
                            Send Events to Facebook
                        </button>

                        <div class="p-2 event-status" id="upload-events-status">  </div>

                    </div>


                </div>
            </fieldset>

        </div>
        <div class="col-md mw-25p">
            <fieldset class="mb-1" data-async="">

                <div class="col p-0 px-3">
                    <legend class="text-black">
                        Import Results
                    </legend>
                </div>

                <div class="row">
                    <div class="p-4 bg-white rounded shadow-sm h-100">
                        <small class="text-muted d-block mb-1">Imported Orders</small>
                        <p id="uploaded-results-imported" class="h3 text-black fw-light">
                            <span class="text-super-muted">Awaiting Upload</span>
                        </p>
                    </div>
                </div>

                <div class="row mt-3">
                    <div class="p-4 bg-white rounded shadow-sm h-100">
                        <small class="text-muted d-block mb-1">Excluded Orders</small>
                        <p id="uploaded-results-excluded" class="h3 text-black fw-light">
                            <span class="text-super-muted">Awaiting upload</span>
                        </p>
                    </div>
                </div>

            </fieldset>

        </div>
    </div>

@stop
