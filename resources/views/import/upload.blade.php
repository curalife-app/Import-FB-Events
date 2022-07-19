@extends('platform::dashboard')

@section('title','FB Data File Upload')
@section('description', 'Upload a CSV file containing orders that need to be mapped and sent as events to Facebook.')

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
                        Set up import file upload
                    </legend>
                </div>

                <div class="bg-white rounded shadow-sm p-4 py-4 d-flex flex-column">

                    <p class="small mt-2 mb-0">
                        Upload list of Shopify orders to import them as <b>Purchase events</b> into Facebook.
                        <br>
                        Files must be CSV and have at least two columns containing Shopify order ID and a key to identify the store.
                        <br>
                        Column indexes can be configured in upload settings below.
                        <br>
                        Event ID is set using Shopify order ID to allow deduplication.
                    </p>

                    <div class="form-group mt-3">
                        <label for="field-raw-file-12d15c1636d67c83d07358db294a5c6dc3cb8878" class="form-label">CSV import file

                        </label>

                        <div data-controller="input" data-input-mask="">
                            <form id="import-file-form">
                            <input class="form-control" name="raw_file" type="file" title="File input example" id="import-file-csv">
                            </form>
                        </div>

                    </div>


                    <h6 id="upload-file-settings-button" class="btn btn-link btn-group-justified pt-2 pb-2 mb-0 pe-0 ps-0 d-flex align-items-center">
                        <svg xmlns="http://www.w3.org/2000/svg" version="1.1" width="1em" height="1em" viewBox="0 0 32 32" class="small me-2" role="img" fill="currentColor" componentname="orchid-icon">
                            <path d="M8.489 31.975c-0.271 0-0.549-0.107-0.757-0.316-0.417-0.417-0.417-1.098 0-1.515l14.258-14.264-14.050-14.050c-0.417-0.417-0.417-1.098 0-1.515s1.098-0.417 1.515 0l14.807 14.807c0.417 0.417 0.417 1.098 0 1.515l-15.015 15.022c-0.208 0.208-0.486 0.316-0.757 0.316z"></path>
                        </svg>
                        Configure upload settings
                    </h6>

                    <div id="upload-file-settings" class="hidden">

                        <p class="small mt-2 mb-0">
                            Here you can configure how to detect columns in the uploaded file.
                            <br>
                            Order number is used to identify the store by checking if it contains correct identification key.
                            <br>
                            Identification is configured for each store separately.
                        </p>
                        <p class="small mt-2 mb-2">
                            <i>Note: Column indexes start with 0.</i>
                        </p>


                        <div class="form-group mt-3">
                            <label for="field-order-id-key-ce623721d8008cbaf50b483118575e59514655be" class="form-label">Order ID Field Index

                            </label>

                            <div data-controller="input" data-input-mask="">
                                <input class="form-control" name="order_id_key" type="number" title="Order ID Field Index" value="0" id="order_id_key">
                            </div>

                            <small class="form-text text-muted">Used to retrieve order data from Shopify.</small>
                        </div>

                        <div class="form-group">
                            <label for="field-order-number-key-e3971f3917f71235cda2530f147b84ed65773ca6" class="form-label">Order Number Field Index

                            </label>

                            <div data-controller="input" data-input-mask="">
                                <input class="form-control" name="order_number_key" type="number" title="Order Number Field Index" value="1" id="order_number_key">
                            </div>

                            <small class="form-text text-muted">
                                Contains keys that allow to identify the store (<b>US</b>, <b>SHLV</b>)
                            </small>
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


                    </div>

                    <div class="form-group mb-0 mt-5 d-flex flex-row">

                        <input id="csrf" name="csrf" value="{{ csrf_token() }}" type="hidden" class="hidden">
                        <button id="upload-events-file-btn" class="btn btn-info">
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
                        Upload results
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
