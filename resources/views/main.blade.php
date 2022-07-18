<div class="bg-white rounded-top shadow-sm mb-3">

    <div class="row g-0">
        <div class="col col-lg-7 mt-6 p-4">

            <h2 class="mt-2 text-dark fw-light">
                Welcome!
            </h2>

            <p>
                Here are the features available in the current system implementation.
            </p>
        </div>
        <div class="d-none d-lg-block col align-self-center text-end text-muted p-4">
            <!--img src="https://cdn.shopify.com/s/files/1/0495/2621/0723/files/logo-colored_201b4ca3-0ff6-4c76-ab65-5033659c30e1.png?v=1620372592" width="150px"/-->
        </div>
    </div>

    <div class="row bg-light m-0 p-4 border-top rounded-bottom">

        <div class="col-md-6 my-2">
            <h3 class="text-muted fw-light">
                <x-orchid-icon path="bar-chart"/>

                <span class="ms-3 text-dark">LIVE Event Tracking</span>
            </h3>
            <p class="ms-md-5 ps-md-1">
                The system is able to capture orders via Shopify webhooks and send
                Facebook Purchase events based on the order data using Facebook Conversion API.
                The orders are deduplicated using event IDs that are being matched to event IDs coming
                from Shopify
            </p>
        </div>

        <div class="col-md-6 my-2">
            <h3 class="text-muted fw-light">
                <x-orchid-icon path="doc"/>

                <span class="ms-3 text-dark">File Import</span>
            </h3>
            <p class="ms-md-5 ps-md-1">
                Another mechanism allows to import events via a CSV file containing orders manually gathered from Shopify.
                Order ID is required to identify an order, along with a key to detect the correct store which the order belongs to.
            </p>
        </div>

        <div class="col-md-6 my-2">
            <h3 class="text-muted fw-light">
                <x-orchid-icon path="cloud-download"/>

                <span class="ms-3 text-dark">Import from Shopify API</span>
            </h3>
            <p class="ms-md-5 ps-md-1">
                In addition, orders can be imported directly from Shopify for a specific time range using Shopify API.
                Since Facebook allows events with time no older than 7 days, all orders exceeding the creation date will be excluded.
            </p>
        </div>

        <div class="col-md-6 my-2">
            <h3 class="text-muted fw-light">
                <x-orchid-icon path="friends"/>

                <span class="ms-3 text-dark">Users management</span>
            </h3>
            <p class="ms-md-5 ps-md-1">
                The platform allows to add users and roles to be able to add staff
                who might be handling events and imports themselves.
            </p>
        </div>

   </div>

</div>

