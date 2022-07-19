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

/**
 * Class FacebookEventImportController
 * @package App\Http\Controllers
 */
class FacebookEventImportController extends Controller
{
    /**
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
     */
    public function renderWebhookControlView()
    {
        $activeWebhooks = [];
        foreach(config('services.shopify') as $store => $storeCredentials) {
            $activeWebhooks[$store] = false;
            $webhookResponse = $this->getShopifyWebhooks($store);
            foreach($webhookResponse as $webhook) {
                if ($webhook['topic'] == 'orders/paid') {
                    $activeWebhooks[$store] = true;
                }
            }
        }

        return view('webhook.toggle', ['activeWebhooks' => $activeWebhooks]);
    }

    /**
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
     */
    public function renderUploadImportView()
    {
        return view('import.upload');
    }

    /**
     * @return \Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\View\Factory|\Illuminate\Contracts\View\View
     */
    public function renderApiImportView()
    {
        return view('import.api');
    }

    /**
     * @param $store
     */
    public function enableWebhooks($store)
    {

    }

    /**
     * @param Request $request
     * @return array|\Illuminate\Contracts\Foundation\Application|\Illuminate\Contracts\Routing\ResponseFactory|\Illuminate\Http\Response
     */
    public function importEventsViaFile(Request $request)
    {
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

    /**
     * @param Request $request
     * @return array|false[]|\Illuminate\Config\Repository[]|\Illuminate\Contracts\Foundation\Application[]|string[]
     */
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
            return array_merge(['input' => [$createdAtMin, $createdAtMax, $importModeFacebook]], $this->sendFBEventsFromShopifyOrders($orders, $importModeFacebook));
        }

        return [
            'importCount' => 0,
            'excludedCount' => 0,
            'excluded' => [],
            'output' => []
        ];
    }

    /**
     * @param $orders
     * @param $importModeFacebook
     * @return array
     * @throws \Throwable
     */
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

            if (!empty($events)) {

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
            }
        }

        return [
            'importCount' => sizeof($events),
            'excludedCount' => sizeof($excluded),
            'excluded' => $excluded,
            'output' => $result,
        ];
    }

    /**
     * @param $store
     * @param Request $request
     * @return array|\FacebookAds\Object\ServerSide\EventResponse
     */
    public function listenShopifyOrderWebhook($store, Request $request)
    {
        $result = [];
        $order = $request->all();

        Log::info('Webhook listener: order/paid caught on store "' . $store . '". Mapping data.');

        try {
            $data = $this->mapShopifyOrderToEventData($order);
        } catch(\Exception $e) {
            Log::error('Webhook listener: Issue mapping order data');
        }

        if (empty($data)) {
            Log::error('Webhook listener: Empty mapped data');
            return $result;
        }

        $events = $this->createEvents($data);

        try {
            Api::init(null, null, config('services.facebook.access_token'), false);
            $result = $this->sendEvents($events);
            if (!empty($data['event_id']) && !empty($data['email'])) {
                Log::notice('Event for order ' . $data['event_id']. ' from email ' . $data['email'] . ' on store '. $store . ' sent to Facebook.');
            }
        } catch(\Exception $e) {
            Log::error('Webhook listener: Event error '. $e->getMessage());
        }

        return $result;
    }

    /**
     * @param $store
     * @return array
     */
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

    /**
     * @param $store
     * @return array
     */
    public function addShopifyPaidOrderWebhook($store)
    {
        $output = [];
        $webhooks = [];
        $webhookFound = false;
        $topic = 'orders/paid';

        try {
            $webhooks = $this->getShopifyWebhooks($store);
        } catch(\Exception $e) {
            $output['status'] = 'error';
            $output['message'] = 'Error retrieving current webhooks.';
            Log::error('Webhook enable: Error getting webhooks on store' . $store . ' - '. $e->getMessage());
        }

        foreach($webhooks as $webhook) {
            if (!empty($webhook['id']) && $webhook['topic'] == $topic) {
                $webhookFound = true;
                Log::error('Webhook enable: Webhook with topic ' . $webhook['topic'] .' already exists with id '. $webhook['id']);
            }
        }

        if (!$webhookFound) {
            try {
                $output = $this->createShopifyWebhook([ 'webhook' => [
                    'topic' => 'orders/paid',
                    'address' => config('app.url') . '/api/' . $store . '/webhooks/order',
                    'format' => 'json'
                ]], $this->getShopifyStoreConfig($store));
                $output['status'] = 'success';
                $output['message'] = 'Webhook activated successfully.';
                Log::info('Webhook enable: ENABLED webhook ' .  $output['id'] . ' on store ' .  $store . ' with topic ' . $output['topic'] . '.');
            } catch(\Exception $e) {
                $output['status'] = 'error';
                $output['message'] = 'Error activating webhook.';
                Log::error('Webhook enable: Error enabling webhooks on store' . $store . ' with topic orders/paid - '. $e->getMessage());
            }
        } else {
            $output['status'] = 'error';
            $output['message'] = 'Webhook already activated.';
        }
        return $output;
    }

    /**
     * @param $store
     * @return array
     */
    public function removeShopifyPaidOrderWebhook($store)
    {
        $output = [];
        $webhooks = [];
        try {
            $webhooks = $this->getShopifyWebhooks($store);
        } catch(\Exception $e) {
            $output['status'] = 'error';
            $output['message'] = 'Error retrieving current webhooks.';
            Log::error('Webhook disable: Error getting webhooks on store' . $store . ' - '. $e->getMessage());
        }

        foreach($webhooks as $webhook) {
            if (!empty($webhook['id']) && $webhook['topic'] == 'orders/paid') {
                try {
                    $output = array_merge($output,
                        (array )$this->deleteShopifyWebhook($webhook['id'], $this->getShopifyStoreConfig($store))
                    );
                    $output['status'] = 'success';
                    $output['message'] = 'Webhook deactivated successfully.';
                    Log::info('Webhook disable: DISABLED webhook ' .  $webhook['id'] . ' on store ' .  $store . ' with topic ' . $webhook['topic'] . '.');
                } catch(\Exception $e) {
                    $output['status'] = 'error';
                    $output['message'] = 'Error deactivating webhook.';
                    Log::error('Webhook disable: Error disabling webhook ' .  $webhook['id'] . ' on store ' .  $store . ' with topic ' . $webhook['topic'] . ' - '. $e->getMessage());
                }
            }
        }
        return $output;
    }

    /**
     * @param $parameters
     * @param $config
     * @return array
     */
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

    /**
     * @param $id
     * @param $config
     * @return array
     */
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

    /**
     * @param $data
     * @return Promise\PromiseInterface
     */
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

    /**
     * @param $events
     * @return \FacebookAds\Object\ServerSide\EventResponse
     */
    private function sendEvents($events)
    {
        $request = (new EventRequest(config('services.facebook.pixel_id')))
            ->setEvents($events);
        return $request->execute();
    }

    /**
     * @param $data
     * @return \FacebookAds\Object\ServerSide\EventResponse
     */
    private function sendTestEvent($data)
    {
        $async_request = (new EventRequest(config('services.facebook.pixel_id')))
            ->setEvents($this->createEvents($data))
            ->setTestEventCode(config('services.facebook.test_event_code'));
        return $async_request->execute();
    }

    /**
     * @param Request $request
     */
    public function sendEvent(Request $request)
    {
        Api::init(null, null, config('services.facebook.access_token'), false);

        $promise = $this->createAsyncRequest($request->all());
        Log::info('FB event fired with data: ' . json_encode($request->all()). ' Response:' . $promise->getState());
    }

    /**
     * @param $ids
     * @param $store
     * @return array|mixed
     */
    public function getShopifyOrdersByIds($ids, $store)
    {
        $parameters = [
            'ids' => implode(',', $ids),
            'status' => 'any',
            'limit' => config('constants.order_limit_shopify')
        ];

        return $this->getPaginatedOrders($parameters, $this->getShopifyStoreConfig($store));
    }

    /**
     * @param $createdAtMin
     * @param $createdAtMax
     * @param $store
     * @return array|mixed
     */
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

    /**
     * @param $store
     * @return array
     */
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

    /**
     * @param $parameters
     * @param $config
     * @param array $orders
     * @return array|mixed
     */
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
            if (sizeof($orders) >= $parameters['limit'] * config('constants.request_limit_shopify')) {
                Log::error('Limit of Shopify requests ('. config('constants.request_limit_shopify') .') exceeded within batch.');
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

    /**
     * @param $order
     * @return array
     */
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

    /**
     * @param $data
     * @return array
     */
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

            /*
             * Custom data contents not optimal for processing
            if (!empty($data['contents'])) {
                if (is_array($data['contents'])) {
                    $data['contents'] = json_encode($data['contents']);
                }
                $custom_data->setContents($data['contents']);
            }*/

            $event->setCustomData($custom_data);
        }

        // Website action source to indicate that conversions were made on Shopify store
        $event->setActionSource(ActionSource::WEBSITE);

        return array($event);
    }

    /**
     * @param $lineItems
     * @return array
     */
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


