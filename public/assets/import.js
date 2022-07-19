var dateRangeInterval;

document.addEventListener("turbo:load", main);
$(document).ready(main);

function main()
{
    updateDateRanges(168);

    $('#upload-file-settings-button').on('click', function() {
        $('#upload-file-settings').slideToggle();
    });

    $('input[type="radio"][name="import-template-date-range"]').change(function() {
        updateDateRanges($(this).val());
    });

    $('.webhooks-toggle').click(function() {
        let toggle = this;
        let store = $(toggle).attr('data-store')
        if (store) {
            setWebhookStatus('', '', store, toggle);
            if (toggle.checked) {
                setWebhookStatus('Processing', 'processing', store, toggle);
                $.post('/admin/webhooks/' + store + '/activate', { '_token': $('#csrf').val() })
                    .done(function(response) {
                        if (response && response.message && response.status) {
                            setWebhookStatus(response.message, response.status, store, toggle);
                        } else {
                            setWebhookStatus('Webhook activated successfully.', 'success', store, toggle);
                        }
                        setTimeout(() => {
                            setWebhookStatus('Active', 'success', store, toggle)
                        }, 3000);
                    })
                    .fail(function(response) {
                        setWebhookStatus('Error activating webhook.', 'error', store, toggle);
                    });
                console.log('webhook for store ' + store + ' turned on');
            } else {
                setWebhookStatus('Processing', 'processing', store, toggle);
                $.post('/admin/webhooks/' + store + '/deactivate', { '_token': $('#csrf').val() })
                    .done(function(response) {
                        if (response && response.message && response.status) {
                            setWebhookStatus(response.message, response.status, store, toggle);
                        } else {
                            setWebhookStatus('Webhook deactivated successfully.', 'success', store, toggle);
                        }
                        setTimeout(() => {
                            setWebhookStatus('Inactive', '', store, toggle)
                        }, 3000);
                    })
                    .fail(function(response) {
                        setWebhookStatus('Error deactivating webhook.', 'error', store, toggle);
                    });
                console.log('webhook for store ' + store + ' turned off');
            }
        }
    });

    $('#upload-events-file-btn').on('click', function(e) {

        let formData = new FormData();

        if ($("#import-file-csv")[0].files.length > 0) {
            formData.append("file", $("#import-file-csv")[0].files[0])
        } else {
            $("#import-file-csv").addClass('error-input');
            return false;
        }

        setProcessingStatus();

        for (const [key, value] of Object.entries(prepareEventFileData())) {
            formData.append(key, value);
        }

        $.ajax({
            type: "POST",
            url: '/admin/events/file', //?' + params,
            data: formData,
            processData: false,
            contentType: false
        })
            .done(function(response) {
                processEventImportSuccess(response);
            })
            .fail(function(response) {
                processEventImportFail(response);
            });


        /* $.post('/admin/events/file?' + params, new FormData($('#import-file-form')[0]))//prepareEventFileData())
             .done(function(response) {
                 processEventImportSuccess(response);
             })
             .fail(function(response) {
                 processEventImportFail(response);
             });*/
    });

    $('#upload-events-api-btn').on('click', function(e) {
        setProcessingStatus();

        $.post('/admin/events/api', prepareEventApiData())
            .done(function(response) {
                processEventImportSuccess(response);
            })
            .fail(function(response) {
                processEventImportFail(response);
            });
    });

    function setWebhookStatus(message, status, store, target) {
        let box = '.webhook-status-' + store;
        $(target).removeAttr('disabled');
        $(box)
            .removeClass('error')
            .removeClass('animate-flicker')
            .removeClass('success')
            .text(message);
        if (status == 'error') {
            $(box).addClass('error');
        } else if (status == 'success') {
            $(box).addClass('success');
        } else if (status == 'processing') {
            $(box).addClass('animate-flicker');
            $(target).attr('disabled', 'disabled');
        }
    }

    function setProcessingStatus()
    {
        $('#uploaded-results-imported, #uploaded-results-excluded').html('<span class="animate-flicker">Processing</span>');
        $('#upload-events-status').removeClass('error').text('');
        $('input').removeClass('error-input').text('');
    }

    function updateDateRanges(period)
    {
        clearInterval(dateRangeInterval);

        if (period <= 0) {
            $('#created_at_min, #created_at_max').attr('disabled', null);
        } else {
            $('#created_at_min, #created_at_max').attr('disabled', 'disabled')
            recalculateDateRanges(period);
            dateRangeInterval = setInterval(function() {
                recalculateDateRanges(period);
            }, 1000);
        }
    }

    function recalculateDateRanges(period)
    {
        $('#created_at_min').val(new Date(
            Date.now() - period * 60 * 60 * 1000
            + 60 * 1000 // 1 minute buffer for potential delays
        ).toISOString().split('.')[0]);
        $('#created_at_max').val(new Date().toISOString().split('.')[0]);
    }


    function prepareEventFileData()
    {
        let data = {
            '_token': $('#csrf').val()
        };
        if ($('#order_id_key').val() !== undefined
            && !isNaN($('#order_id_key').val())
            && $('#order_id_key').val() >= 0
        ) {
            data.order_id_key = $('#order_id_key').val();
        }
        if ($('#order_number_key').val() !== undefined
            && !isNaN($('#order_number_key').val())
            && $('#order_number_key').val() >= 0
        ) {
            data.order_number_key = $('#order_number_key').val();
        }
        if ($('input[name="import-mode"]:checked').length
            && $('input[name="import-mode"]:checked').val()
        ) {
            data.import_mode = $('input[name="import-mode"]:checked').val();
        }
        return data;
    }

    function prepareEventApiData()
    {
        let data = {
            '_token': $('#csrf').val()
        };
        if ($('#created_at_min').val() !== undefined) {
            data.created_at_min = $('#created_at_min').val();
        }
        if ($('#created_at_max').val() !== undefined) {
            data.created_at_max = $('#created_at_max').val();
        }
        if ($('input[name="import-mode"]:checked').length
            && $('input[name="import-mode"]:checked').val()
        ) {
            data.import_mode = $('input[name="import-mode"]:checked').val();
        }
        return data;
    }

    function processEventImportSuccess(response)
    {
        if (response.importCount != undefined) {
            $('#uploaded-results-imported').text(response.importCount);
        } else {
            $('#uploaded-results-imported').text('No data');
        }

        if (response.excludedCount !== undefined) {
            $('#uploaded-results-excluded').text(response.excludedCount);
        } else {
            $('#uploaded-results-excluded').text('No data');
        }

        if (response.importCount != undefined && response.excludedCount !== undefined) {
            $('#upload-events-status').text('Event import successful.');
        }
    }

    function processEventImportFail(response)
    {
        $('#uploaded-results-imported, #uploaded-results-excluded').html('<span class="error">Processing Error</span>');
        if (response && response.output) {
            $('#upload-events-status').addClass('error').html('Processing failed with error:<br>' + response.output);
        }
    }

}
