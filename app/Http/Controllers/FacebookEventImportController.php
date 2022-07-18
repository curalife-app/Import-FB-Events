<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

use FacebookAds\Api;
use FacebookAds\Object\ServerSide\CustomData;
use FacebookAds\Object\ServerSide\Event;
use FacebookAds\Object\ServerSide\EventRequest;
use FacebookAds\Object\ServerSide\EventRequestAsync;
use FacebookAds\Object\ServerSide\UserData;
use FacebookAds\Object\ServerSide\ActionSource;

use ClarityTech\Shopify\Exceptions\ShopifyApiException;
use ClarityTech\Shopify\Facades\Shopify as ShopifyAPI;

use Illuminate\Support\Facades\Log;

use GuzzleHttp\Exception\RequestException;
use GuzzleHttp\Promise;

class FacebookEventImportController extends Controller
{
    private $requestsLimitShopify = 5; //100;

    private $importModeFacebook = 'batch'; //async

    public function renderUploadImportView()
    {
        return view('import.upload');
    }

    public function renderApiImportView()
    {
        return view('import.api');
    }

    public function importEventsViaFile(Request $request)
    {
        //dd($request->file('file')->getContent());
        $orders = [];
        $ids = [];

        if ($request->file('file')) {
            $import = array_map('str_getcsv', file($request->file('file')));
        } else {
            Log::error('No import file specified');
            return response([
                'output' => 'No import file specified'
            ], 400);
        }

        $importModeFacebook = config('constants.import_mode_facebook', 'batch');

        if (!empty($request->get('import_mode')) && in_array($request->get('import_mode'), ['batch', 'async'])) {
            $importModeFacebook = $request->get('import_mode');
        }

        //$import = array_map('str_getcsv', file(storage_path() .'/import/p1_since_20220619.csv'));

        // Key map for the import CSV file
        $importKeys = [
            'id' => 0,
            'number' => 1,
        ];

        if ($request->get('order_id_key') !== null) {
            $importKeys['id'] = intval($request->get('order_id_key'));
        }

        if ($request->get('order_id_number') !== null) {
            $importKeys['number'] = intval($request->get('order_id_number'));
        }

        // Retrieve and split order IDs based on the store type
        foreach($import as $order) {
            if ($order[ $importKeys['id'] ] != 'ORDER_ID') {

                // Iterate over available stores and collect order IDs based on identification key from the import file
                foreach(config('services.shopify') as $storeId => $store) {
                    if (strpos($order[ $importKeys['number'] ], $store['import_ident_key']) !== false) {
                        $ids[$storeId][] = $order[ $importKeys['id'] ];
                    }
                }

            }
        }

        // Iterate over available stores and get all the orders using collected order IDs
        foreach(config('services.shopify') as $storeId => $store) {
            $orders = array_merge($orders, $this->getShopifyOrdersByIds($ids[$storeId], $storeId));
        }

        if (!empty($orders)) {
            return $this->sendFBEventsFromShopifyOrders($orders, $importModeFacebook);
        }

        return [
            'importCount' => 0,
            'excludedCount' => 0,
            'excluded' => [],
            'output' => []
        ];
    }

    public function importEventsViaApi(Request $request)
    {
        $orders = [];
        $createdAtMin = date('c', strtotime(config('constants.oldest_event_time_string')));
        $createdAtMax = date('c');

        $importModeFacebook = config('constants.import_mode_facebook', 'batch');

        if (!empty($request->get('import_mode')) && in_array($request->get('import_mode'), ['batch', 'async'])) {
            $importModeFacebook = $request->get('import_mode');
        }

        if (!empty($request->get('created_at_min')) && strtotime($request->get('created_at_min')) < time()) {
            $createdAtMin = date('c', strtotime($request->get('created_at_min') . ' UTC'));
        }

        if (!empty($request->get('created_at_max')) && strtotime($request->get('created_at_max')) > $createdAtMin) {
            $createdAtMax = date('c', strtotime($request->get('created_at_max') . ' UTC'));
        }

        if (strtotime($createdAtMin) < strtotime($createdAtMax)) {
            Log::info('Events import via API called for orders from '. $createdAtMin . ' to '. $createdAtMax);

            // Iterate over available stores and get all the orders in a specific date range
            foreach(config('services.shopify') as $storeId => $store) {
                $orders = array_merge($orders, $this->getShopifyOrdersByDateRange($createdAtMin, $createdAtMax, $storeId));
            }
        }

        if (!empty($orders)) {
            return array_merge(['stuff' => [$createdAtMin, $createdAtMax, $importModeFacebook]], $this->sendFBEventsFromShopifyOrders($orders, $importModeFacebook));
        }

        return [
            'importCount' => 0,
            'excludedCount' => 0,
            'excluded' => [],
            'output' => []
        ];
    }

    private function sendFBEventsFromShopifyOrders($orders, $importModeFacebook)
    {
        $result = [];
        $events = [];
        $excluded = [];

        if (!empty($orders)) {

            // According to FB documentation, events older than 7 days are not allowed
            // Source: event_time - https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/server-event/
            $oldestEventTimestamp = strtotime(config('constants.oldest_event_time_string'));

            foreach ($orders as $order) {

                $data = $this->mapShopifyOrderToEventData($order);

                if (empty($data)) continue;

                // Exclude orders that are older than 7 days
                if (!empty($data['timestamp']) && $data['timestamp'] < $oldestEventTimestamp) {
                    $excluded[] = $order['id'];
                    $excludedDates[] = $order['created_at'];
                    continue;
                }

                $events = array_merge($events, $this->createEvents($data));
            }

            unset($orders);

            /*if (!empty($events)) {

                Log::info('Events ready to import: '. sizeof($events) .'. Events excluded: '. sizeof($excluded). '.');

                // Initialize Facebook API
                Api::init(null, null, config('services.facebook.access_token'), false);

                // Split events into batches to be sent in. Maximum allowed events per batch: 1000
                if ($importModeFacebook == 'batch') {

                    $batches = array_chunk($events, config('constants.event_batch_limit_facebook'));
                    foreach ($batches as $batchIndex => $batch) {
                        // Send all events to Facebook in a batch
                        $result = array_merge($result, (array)$this->sendEvents($batch));
                        Log::info('Batch ' . $batchIndex . '  import processed. Total events sent: ' . sizeof($batch) . '.');
                    }

                } else if ($importModeFacebook == 'async') {

                    $i = 0;
                    $promises = [];
                    foreach ($events as $event) {
                        $promises["Request " .$i++] = $this->createAsyncRequest($event);
                    }

                    $responseAsync = Promise\unwrap($promises);
                    foreach ($responseAsync as $request_name => $response) {
                        try {
                            $result[$i] = json_decode($response->getBody());
                            Log::info($request_name . ": " . $response->getBody() . "\n");
                        } catch (\Exception $e) {
                            Log::error($request_name . ': ' . 'Could not retrieve response body');
                        }

                    }
                    Log::info("Async event import processed. Total requests: " . sizeof($responseAsync));
                }
            }*/
        }

        return [
            'importCount' => sizeof($events),
            'excludedCount' => sizeof($excluded),
            'excluded' => $excluded,
            'output' => $result,
        ];
    }

    public function sendBulkEvents(Request $request)
    {
        //$orders = array_map('str_getcsv', file(storage_path() .'/import/p1_since_20220619.csv'));
        $import = array_map('str_getcsv', file(storage_path() .'/import/p1_since_20220619.csv'));

        $result = [];
        $excludedOrders = [];

        $ids = [];

        $orders = [];
        $events = [];
        $batches = [];

        $excludedDates = [];


        // Key map for the import CSV file
        $importKeys = [
            'id' => 0,
            'number' => 1,
            'email' => 2,
        ];

        // Retrieve and split order IDs based on the store type
        foreach($import as $order) {
            if ($order[ $importKeys['id'] ] != 'ORDER_ID') {

                // Iterate over available stores and collect order IDs based on identification key from the import file
                foreach(config('services.shopify') as $storeId => $store) {
                    if (strpos($order[ $importKeys['number'] ], $store['import_ident_key']) !== false) {
                        $ids[$storeId][] = $order[ $importKeys['id'] ];
                    }
                }

            }
        }

        // Iterate over available stores and get all the orders using collected order IDs
        foreach(config('services.shopify') as $storeId => $store) {
            $orders = array_merge($orders, $this->getShopifyOrdersByIds($ids[$storeId], $storeId));
        }

        //dd(sizeof($orders), $orders);

        $sentOrders = ['4633296797890', '4632955322562', '4635232174231', '4635239121047', '4635228635287'];

        if (!empty($orders)) {

            // According to FB documentation, events older than 7 days are not allowed
            // Source: event_time - https://developers.facebook.com/docs/marketing-api/conversions-api/parameters/server-event/
            $oldestEventTimestamp = strtotime(config('constants.oldest_event_time_string'));

            //unset($orders[0]);
            foreach($orders as $order) {

                //@todo remove this part
                // Skip some already sent orders
                if (in_array((string) $order['id'], $sentOrders)) continue;

                //if ((string) $order['id'] != '4635140522135') continue;

                $data = $this->mapShopifyOrderToEventData($order);

                if (empty($data)) continue;

                // Exclude orders that are older than 7 days
                if (!empty($data['timestamp']) && $data['timestamp'] < $oldestEventTimestamp) {
                    $excludedOrders[] = $order['id'];
                    $excludedDates[] = $order['created_at'];
                    continue;
                }

                $events = array_merge($events, $this->createEvents($data));

                //$promises["Request ".$i] = $this->sendTestEvent($data);

            }
            //echo 'Batch import ready to fire. Total events to send: '. sizeof($events) .'. Events excluded: '. sizeof($excludedOrders) . '\n';

            /*if (!empty($events)) {

                // Initialize Facebook API
                Api::init(null, null, config('services.facebook.access_token'), false);

                // Split events into batches to be sent in. Maximum allowed events per batch: 1000
                if ($this->importModeFacebook == 'batch') {

                    $batches = array_chunk($events, config('constants.event_batch_limit_facebook'));
                    foreach($batches as $batchIndex => $batch) {
                        // Send all events to Facebook in a batch
                        $result = array_merge($result, (array) $this->sendEvents($batch));
                        Log::info('Batch ' . $batchIndex . '  import processed. Total events sent: '. sizeof($batch) .'. Events excluded: '. sizeof($excludedOrders) . '.');
                    }
                }

                if ($this->importModeFacebook == 'async') {

                    $i = 0;
                    $promises = [];
                    foreach($events as $event) {
                        $promises["Request " . $i++] = $this->createAsyncRequest($data);
                    }

                    $response3 = Promise\unwrap($promises);
                    foreach ($response3 as $request_name => $response) {
                        try {
                            $result[$i] = json_decode($response->getBody());
                            Log::info($request_name . ": " . $response->getBody()."\n");
                        } catch (\Exception $e) {
                            Log::error($request_name . ': ' . 'Could not retrieve response body');
                        }

                    }
                    Log::info("Async - Multiple async requests OK. Total requests: " . sizeof($response3) ."\n");
                }
            }*/

        }
        return [
            'importCount' => sizeof($events),
            'excludedCount' => sizeof($excludedOrders),
            'excluded' => $excludedOrders,
            'output' => $result
        ];
    }

    public function listenShopifyOrderWebhook($store, Request $request)
    {
        $result = [];
        $order = $request->all();

        Log::info('Webhook order/paid caught on store "' . $store . '". Mapping data.');

        //$tempData = '{"id":4642403123351,"admin_graphql_api_id":"gid:\/\/shopify\/Order\/4642403123351","app_id":580111,"browser_ip":"76.255.148.120","buyer_accepts_marketing":false,"cancel_reason":null,"cancelled_at":null,"cart_token":"b797e9d3141e2ad0721ab8420406db97","checkout_id":23701488959639,"checkout_token":"0d9fb13ebf7bd768f117961cb6856dcd","client_details":{"accept_language":"en-US,en;q=0.9","browser_height":749,"browser_ip":"76.255.148.120","browser_width":1583,"session_hash":null,"user_agent":"Mozilla\/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit\/537.36 (KHTML, like Gecko) Chrome\/103.0.0.0 Safari\/537.36"},"closed_at":null,"confirmed":true,"contact_email":"goodsmg@hotmail.com","created_at":"2022-07-13T17:05:55Z","currency":"USD","current_subtotal_price":"100.00","current_subtotal_price_set":{"shop_money":{"amount":"100.00","currency_code":"USD"},"presentment_money":{"amount":"100.00","currency_code":"USD"}},"current_total_discounts":"20.00","current_total_discounts_set":{"shop_money":{"amount":"20.00","currency_code":"USD"},"presentment_money":{"amount":"20.00","currency_code":"USD"}},"current_total_duties_set":null,"current_total_price":"100.00","current_total_price_set":{"shop_money":{"amount":"100.00","currency_code":"USD"},"presentment_money":{"amount":"100.00","currency_code":"USD"}},"current_total_tax":"0.00","current_total_tax_set":{"shop_money":{"amount":"0.00","currency_code":"USD"},"presentment_money":{"amount":"0.00","currency_code":"USD"}},"customer_locale":"en-US","device_id":null,"discount_codes":[{"code":"TWENTYDNCQWPEK","amount":"20.00","type":"fixed_amount"}],"email":"goodsmg@hotmail.com","estimated_taxes":false,"financial_status":"paid","fulfillment_status":null,"gateway":"shopify_installments","landing_site":"\/pages\/glucose-support-curalin-pack-summer2022?utm_source=blast&utm_medium=email&utm_campaign=summer_sale&utm_content=prospects_no_comm_no_au&utm_term=global&vgo_ee=R6V7XBK\/poJL\/iCHsFlH+kzkASpiHornD\/z2wZTd1jg=","landing_site_ref":null,"location_id":null,"name":"US72441","note":null,"note_attributes":[],"number":71441,"order_number":72441,"order_status_url":"https:\/\/curalife.com\/45224591511\/orders\/c2cb9b7d58b68a2c97215c7a8673fefb\/authenticate?key=328f6891209466928f3686f30d746c48","original_total_duties_set":null,"payment_gateway_names":["shopify_installments"],"phone":null,"presentment_currency":"USD","processed_at":"2022-07-13T17:05:54Z","processing_method":"direct","reference":null,"referring_site":"","source_identifier":null,"source_name":"web","source_url":null,"subtotal_price":"100.00","subtotal_price_set":{"shop_money":{"amount":"100.00","currency_code":"USD"},"presentment_money":{"amount":"100.00","currency_code":"USD"}},"tags":"en-US, TWENTYDNCQWPEK","tax_lines":[],"taxes_included":false,"test":false,"token":"c2cb9b7d58b68a2c97215c7a8673fefb","total_discounts":"20.00","total_discounts_set":{"shop_money":{"amount":"20.00","currency_code":"USD"},"presentment_money":{"amount":"20.00","currency_code":"USD"}},"total_line_items_price":"120.00","total_line_items_price_set":{"shop_money":{"amount":"120.00","currency_code":"USD"},"presentment_money":{"amount":"120.00","currency_code":"USD"}},"total_outstanding":"0.00","total_price":"100.00","total_price_set":{"shop_money":{"amount":"100.00","currency_code":"USD"},"presentment_money":{"amount":"100.00","currency_code":"USD"}},"total_price_usd":"100.00","total_shipping_price_set":{"shop_money":{"amount":"0.00","currency_code":"USD"},"presentment_money":{"amount":"0.00","currency_code":"USD"}},"total_tax":"0.00","total_tax_set":{"shop_money":{"amount":"0.00","currency_code":"USD"},"presentment_money":{"amount":"0.00","currency_code":"USD"}},"total_tip_received":"0.00","total_weight":299,"updated_at":"2022-07-13T17:06:09Z","user_id":null,"billing_address":{"first_name":"Susan","address1":"4536 Bremer Street Southwest","phone":"+16164467037","city":"Grandville","zip":"49418","province":"Michigan","country":"United States","last_name":"Good","address2":"","company":"","latitude":42.8979585,"longitude":-85.7756951,"name":"Susan Good","country_code":"US","province_code":"MI"},"customer":{"id":4259833643159,"email":"goodsmg@hotmail.com","accepts_marketing":false,"created_at":"2020-11-18T13:15:44Z","updated_at":"2022-07-13T17:05:56Z","first_name":"Susan","last_name":"Good","orders_count":3,"state":"enabled","total_spent":"425.00","last_order_id":4642403123351,"note":null,"verified_email":true,"multipass_identifier":null,"tax_exempt":false,"phone":"+16164467037","tags":"Active_Account, P2, Returning_customer, swell_vip_green","last_order_name":"US72441","currency":"USD","accepts_marketing_updated_at":"2022-04-14T14:47:00Z","marketing_opt_in_level":null,"tax_exemptions":[],"sms_marketing_consent":{"state":"unsubscribed","opt_in_level":"single_opt_in","consent_updated_at":"2021-07-09T12:43:03Z","consent_collected_from":"OTHER"},"admin_graphql_api_id":"gid:\/\/shopify\/Customer\/4259833643159","default_address":{"id":7423511265431,"customer_id":4259833643159,"first_name":"Susan","last_name":"Good","company":"","address1":"4536 Bremer Street Southwest","address2":"","city":"Grandville","province":"Michigan","country":"United States","zip":"49418","phone":"+16164467037","name":"Susan Good","province_code":"MI","country_code":"US","country_name":"United States","default":true}},"discount_applications":[{"target_type":"line_item","type":"discount_code","value":"20.0","value_type":"fixed_amount","allocation_method":"across","target_selection":"all","code":"TWENTYDNCQWPEK"}],"fulfillments":[],"line_items":[{"id":11237992267927,"admin_graphql_api_id":"gid:\/\/shopify\/LineItem\/11237992267927","destination_location":{"id":3512258986135,"country_code":"US","province_code":"MI","name":"Susan Good","address1":"4536 Bremer Street Southwest","address2":"","city":"Grandville","zip":"49418"},"fulfillable_quantity":2,"fulfillment_service":"manual","fulfillment_status":null,"gift_card":false,"grams":150,"name":"CuraLin Prime Day","origin_location":{"id":2442367369367,"country_code":"US","province_code":"NJ","name":"CuraLife","address1":"208 Paterson Plank Road","address2":"","city":"Union City","zip":"07087"},"pre_tax_price":"100.00","pre_tax_price_set":{"shop_money":{"amount":"100.00","currency_code":"USD"},"presentment_money":{"amount":"100.00","currency_code":"USD"}},"price":"60.00","price_set":{"shop_money":{"amount":"60.00","currency_code":"USD"},"presentment_money":{"amount":"60.00","currency_code":"USD"}},"product_exists":true,"product_id":7323929772183,"properties":[],"quantity":2,"requires_shipping":true,"sku":"CuraLin Single Unit","tax_code":"PF050700","taxable":true,"title":"CuraLin Prime Day","total_discount":"0.00","total_discount_set":{"shop_money":{"amount":"0.00","currency_code":"USD"},"presentment_money":{"amount":"0.00","currency_code":"USD"}},"variant_id":41487981346967,"variant_inventory_management":"shopify","variant_title":"","vendor":"Curalife Commerce","tax_lines":[{"channel_liable":false,"price":"0.00","price_set":{"shop_money":{"amount":"0.00","currency_code":"USD"},"presentment_money":{"amount":"0.00","currency_code":"USD"}},"rate":0.06,"title":"MI STATE TAX"}],"duties":[],"discount_allocations":[{"amount":"20.00","amount_set":{"shop_money":{"amount":"20.00","currency_code":"USD"},"presentment_money":{"amount":"20.00","currency_code":"USD"}},"discount_application_index":0}]}],"payment_terms":null,"refunds":[],"shipping_address":{"first_name":"Susan","address1":"4536 Bremer Street Southwest","phone":"+16164467037","city":"Grandville","zip":"49418","province":"Michigan","country":"United States","last_name":"Good","address2":"","company":"","latitude":null,"longitude":null,"name":"Susan Good","country_code":"US","province_code":"MI"},"shipping_lines":[{"id":3758275068055,"carrier_identifier":"6fc5fd19118ccc10de4b96d823c0b311","code":"shipscout_default","delivery_category":null,"discounted_price":"0.00","discounted_price_set":{"shop_money":{"amount":"0.00","currency_code":"USD"},"presentment_money":{"amount":"0.00","currency_code":"USD"}},"phone":null,"price":"0.00","price_set":{"shop_money":{"amount":"0.00","currency_code":"USD"},"presentment_money":{"amount":"0.00","currency_code":"USD"}},"requested_fulfillment_service_id":null,"source":"ShipScout","title":"Standard Shipping","tax_lines":[{"channel_liable":false,"price":"0.00","price_set":{"shop_money":{"amount":"0.00","currency_code":"USD"},"presentment_money":{"amount":"0.00","currency_code":"USD"}},"rate":0.06,"title":"MI STATE TAX"}],"discount_allocations":[]}]}';
        //$tempData = json_decode($tempData, true);
        /* $tempData = $orderData = [
            'line_items' => [
                [
                    'variant_id' => '37738442031302',
                    'quantity' => 1
                ]
            ],
            'customer' => [
                "email" => "constantine@smarketly.com",
                "first_name" => "Constantine",
                "last_name" => "Tester",
            ],
            "email" => "constantine@smarketly.com",
            'shipping_address' => [
                "address1"=> "123 Amoebobacterieae St",
                "address2"=> "",
                "city"=> "Ottawa",
                "company"=> null,
                "country"=> "Canada",
                "first_name"=> "Bob",
                "last_name"=> "Bobsen", //
                "latitude"=> "45.41634",
                "longitude"=> "-75.6868",
                "phone"=> "555-625-1199",
                "province"=> "Ontario",
                "zip"=> "K2P0V6",
                "name"=> "Bob Bobsen",
                "country_code"=> "CA",
                "province_code"=> "ON"
            ],
            'billing_address' => [
                "address1"=> "123 Amoebobacterieae St",
                "address2"=> "",
                "city"=> "Ottawa",
                "company"=> null,
                "country"=> "Canada",
                "first_name"=> "Bob",
                "last_name"=> "Bobsen",
                "latitude"=> "45.41634",
                "longitude"=> "-75.6868",
                "phone"=> "555-625-1199",
                "province"=> "Ontario",
                "zip"=> "K2P0V6",
                "name"=> "Bob Bobsen",
                "country_code"=> "CA",
                "province_code"=> "ON"
            ],
            "shipping_line" => [
                "handle" => "shopify-Standard-21.53",
                "price" => 21.53,
                "title" => "Standard"
            ]
        ];*/

        $incomingDataFile = fopen(storage_path() . '/export/test-order-incoming-data.json', 'w');
        fwrite($incomingDataFile, json_encode($order));
        fclose($incomingDataFile);

        try {
            $data = $this->mapShopifyOrderToEventData($order);
            $mappedDataFile = fopen(storage_path() . '/export/test-order-mapped-data.json', 'w');
            fwrite($mappedDataFile, json_encode($data));
            fclose($mappedDataFile);
        } catch(\Exception $e) {}

        if (empty($data)) {
            Log::error('Webhook listener: Empty mapped data');
            return $result;
        }

        $events = $this->createEvents($data);
        $eventDataFile = fopen(storage_path() . '/export/test-order-event-data.json', 'w');
        fwrite($eventDataFile, print_r($events, true));
        fclose($eventDataFile);

        try {
            //$result = $this->sendEvents($events);
            if (!empty($data['event_id']) && !empty($data['email'])) {
                Log::notice('Event for order ' . $data['event_id']. ' from email ' . $data['email'] . ' on store '. $store . ' sent to Facebook.');
            }
        } catch(\Exception $e) {
            Log::error('Webhook listener: Event error '. $e->getMessage());
        }

        return $result;
    }

    public function getShopifyWebhooks($store)
    {
        $response = [];
        $url = 'admin/webhooks.json';
        $config = $this->getShopifyStoreConfig($store);
        try {
            $response = ShopifyAPI::setShop($config['host'], $config['token'])
                ->get($url, []);
        } catch (\Exception $e) {
            Log::error(__FUNCTION__ . ' - ShopifyApiException: '. $e->getMessage());
        }
        return $response;
    }

    public function addShopifyPaidOrderWebhook($store)
    {
        return $this->createShopifyWebhook([ 'webhook' => [
            'topic' => 'orders/paid',
            'address' => 'https://1569-176-113-167-62.ngrok.io'. '/api/' . $store . '/webhooks/order', //config('app.url') . '/order/',
            'format' => 'json'
        ]], $this->getShopifyStoreConfig($store));
    }

    public function removeShopifyPaidOrderWebhook($store, $id)
    {
        return $this->deleteShopifyWebhook($id, $this->getShopifyStoreConfig($store));
    }

    private function createShopifyWebhook($parameters, $config)
    {
        $response = [];
        $url = 'admin/webhooks.json';
        try {
            $response = ShopifyAPI::setShop($config['host'], $config['token'])
                ->post($url, $parameters);
        } catch (ShopifyApiException $e) {
            Log::error(__FUNCTION__ . ' - ShopifyApiException: '. $e->getMessage());
        }
        return $response;
    }

    private function deleteShopifyWebhook($id, $config)
    {
        $response = [];
        $url = 'admin/webhooks/' . $id . '.json';
        try {
            $response = ShopifyAPI::setShop($config['host'], $config['token'])
                ->delete($url);
        } catch (ShopifyApiException $e) {
            Log::error(__FUNCTION__ . ' - ShopifyApiException: '. $e->getMessage());
        }
        return $response;
    }

    private function createAsyncRequest($data)
    {
        $async_request = (new EventRequestAsync(config('services.facebook.pixel_id')))
            ->setEvents($this->createEvents($data));
        return $async_request->execute()
            ->then(
                null,
                function (RequestException $e) {
                    Log::error('Issue with FB request: ' . $e->getMessage());
                    print(
                        "Error!!!\n" .
                        $e->getMessage() . "\n" .
                        $e->getRequest()->getMethod() . "\n"
                    );
                }
            );
    }

    private function sendEvents($events)
    {
        $request = (new EventRequest(config('services.facebook.pixel_id')))
            ->setEvents($events);
        return $request->execute();
    }

    private function sendTestEvent($data)
    {
        $async_request = (new EventRequest(config('services.facebook.pixel_id')))
            ->setEvents($this->createEvents($data))
            ->setTestEventCode(config('services.facebook.test_event_code'));
        return $async_request->execute();
    }

    public function sendEvent(Request $request)
    {
        Api::init(null, null, config('services.facebook.access_token'), false);

        $promise = $this->createAsyncRequest($request->all());
        Log::info('FB event fired with data: ' . json_encode($request->all()). ' Response:' . $promise->getState());

        print("Request 1 state: " . $promise->getState() . "\n");
        print("Async request - OK.\n");
    }

    public function getShopifyOrdersByIds($ids, $store)
    {
        $parameters = [
            'ids' => implode(',', $ids),
            'status' => 'any',
            'limit' => config('constants.order_limit_shopify')
        ];

        return $this->getPaginatedOrders($parameters, $this->getShopifyStoreConfig($store));
    }

    private function getShopifyOrdersByDateRange($createdAtMin, $createdAtMax, $store)
    {
        $parameters = [
            'created_at_min' => $createdAtMin,
            'created_at_max' => $createdAtMax,
            'status' => 'any',
            'limit' => config('constants.order_limit_shopify')
        ];

        return $this->getPaginatedOrders($parameters, $this->getShopifyStoreConfig($store));
    }

    private function getShopifyStoreConfig($store): array
    {
        $config = [];

        // Check for configurations available for specific store
        if (array_key_exists($store, config('services.shopify'))) {
            $config = [
                'host' => config('services.shopify')[$store]['host'],
                'token' => config('services.shopify')[$store]['token']
            ];
        } else {
            // Shouldn't reach here since middleware will check the store beforehand
            Log::error('Config for store ' . $store . ' not found');
        }

        return $config;
    }

    private function getPaginatedOrders($parameters, $config, $orders = [])
    {
        $url = 'admin/orders.json';

        // Start process only if configs are set
        if (!empty($config['host']) && !empty($config['token'])) {
            try {
                // Merge newly retrieved orders with existing ones
                $orders = array_merge(
                    $orders,
                    ShopifyAPI::setShop($config['host'], $config['token'])
                    ->get($url, $parameters)
                );
            } catch (ShopifyApiException $e) {
                Log::error('ShopifyApiException: '. $e->getMessage());
            }

            // Limit amount of requests sent to Shopify to avoid infinite loops
            if (sizeof($orders) >= $parameters['limit'] * $this->requestsLimitShopify) {
                Log::error('Limit of Shopify requests ('. $this->requestsLimitShopify .') exceeded within batch.');
                return $orders;
            }

            // Recursive pagination mechanism based on https://shopify.dev/api/usage/pagination-rest
            if (ShopifyAPI::hasHeader('Link')
                && !empty(ShopifyAPI::getHeader('Link')[0])
                && stripos(ShopifyAPI::getHeader('Link')[0], 'rel="next"') !== false        //only activate pagination when Shopify confirms next page
            ) {
                // Separate Shopify link data from rel parameter within angled brackets
                preg_match_all('/<([^>]+)>/', ShopifyAPI::getHeader('Link')[0], $matches);

                if (!empty($matches[1][0])) {

                    // Only take items inside the angled brackets and first match only
                    $queryString = parse_url($matches[1][0], PHP_URL_QUERY);

                    if (!empty($queryString)) {

                        // Save pagination information to parameters for the next request
                        parse_str($queryString, $parameters);

                        // Recursive call to keep making paginated requests if there are more pages available
                        $orders = $this->getPaginatedOrders($parameters, $config, $orders);

                    }
                }
            }

        }
        return $orders;
    }

    private function mapShopifyOrderToEventData($order): array
    {
        $data = [];

        // Exit if required parameters are not available
        if (empty($order['id']) || empty($order['currency']) || empty($order['current_total_price'])) {
            return $data;
        }

        // Required data map
        $data['event'] = 'Purchase';
        $data['timestamp'] = (!empty($order['created_at']) ? strtotime($order['created_at']) : time());
        $data['event_id'] = $order['id'];
        $data['currency'] = $order['currency'];
        $data['value'] = (float) $order['current_total_price'];

        // Optional data map
        if (!empty($order['email']))                            $data['email'] = str_replace('.@', '@', $order['email']);
        if (!empty($order['customer']['first_name']))           $data['first_name'] = $order['customer']['first_name'];
        if (!empty($order['customer']['last_name']))            $data['last_name'] = $order['customer']['last_name'];
        if (!empty($order['billing_address']['city']))          $data['city'] = $order['billing_address']['city'];
        if (!empty($order['billing_address']['country_code']))  $data['country_code'] = $order['billing_address']['country_code'];
        if (!empty($order['billing_address']['zip']))           $data['zip_code'] = $order['billing_address']['zip'];
        if (!empty($order['billing_address']['phone']))         $data['phone'] = $order['billing_address']['phone'];

        // Device data map
        if (!empty($order['client_details']['browser_ip']))     $data['ip'] = $order['client_details']['browser_ip'];
        if (!empty($order['client_details']['user_agent']))     $data['user_agent'] = $order['client_details']['user_agent'];

        if (!empty($order['line_items']))                       $data['contents'] = $this->getCustomDataStringFromLineItems($order['line_items']);

        return $data;
    }

    private function createEvents($data): array
    {
        $ip = $_SERVER['REMOTE_ADDR'];
        $userAgent = $_SERVER['HTTP_USER_AGENT'];
        $timestamp = time();

        if (!empty($data['ip']))            $ip = $data['ip'];
        if (!empty($data['user_agent']))    $userAgent = $data['user_agent'];
        if (!empty($data['timestamp']))     $timestamp = $data['timestamp'];

        $user_data = (new UserData())
            ->setClientIpAddress($ip)
            ->setClientUserAgent($userAgent);

        if (!empty($data['fbc']))           $user_data->setFbc($data['fbc']);
        if (!empty($data['fbp']))           $user_data->setFbp($data['fbp']);
        if (!empty($data['email']))         $user_data->setEmail($data['email']);
        if (!empty($data['first_name']))    $user_data->setFirstName($data['first_name']);
        if (!empty($data['last_name']))     $user_data->setLastName($data['last_name']);
        if (!empty($data['city']))          $user_data->setCity($data['city']);
        if (!empty($data['country_code']))  $user_data->setCountryCode($data['country_code']);
        if (!empty($data['zip_code']))      $user_data->setZipCode($data['zip_code']);
        if (!empty($data['phone']))         $user_data->setPhone($data['phone']);
        if (!empty($data['user_agent']))    $user_data->setClientUserAgent($data['user_agent']);
        if (!empty($data['ip']))            $user_data->setClientIpAddress($data['ip']);

        $event = (new Event())
            ->setEventName($data['event'])
            ->setEventTime($timestamp);

        if (!empty($data['url']))           $event->setEventSourceUrl($data['url']);
        if (!empty($data['event_id']))      $event->setEventId($data['event_id']);


        $event->setUserData($user_data);

        if (!empty($data['currency']) && isset($data['value'])) {
            $custom_data = (new CustomData())
                ->setCurrency($data['currency'])
                ->setValue($data['value']);

            if (!empty($data['contents'])) {
                /*if (is_array($data['contents'])) {
                    $data['contents'] = json_encode($data['contents']);
                }*/
                //$custom_data->setContents($data['contents']);
            }

            $event->setCustomData($custom_data);
        }

        // Website action source to indicate that conversions were made on Shopify store
        $event->setActionSource(ActionSource::WEBSITE);

        return array($event);
    }

    private function getCustomDataStringFromLineItems($lineItems)
    {
        //dd($lineItems);
        $contents = [];
        foreach($lineItems as $index => $lineItem) {
            $currentLineItem = [];
            $currentLineItem['id'] = $lineItem['sku'];
            $currentLineItem['quantity'] = $lineItem['quantity'];
            $currentLineItem['item_price'] = $lineItem['price'];
            $contents[$index] = json_encode($currentLineItem);
        }
        return $contents;
    }
}


