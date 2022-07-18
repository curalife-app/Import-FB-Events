<p align="center"><a href="https://curalife.com" target="_blank">
<img src="https://cdn.shopify.com/s/files/1/0495/2621/0723/files/logo-colored_201b4ca3-0ff6-4c76-ab65-5033659c30e1.png?v=1620372592" width="110">
</a>
</p>

<p align="center"><a href="https://smarketly.com" target="_blank">
<img src="https://s3-us-west-2.amazonaws.com/static.smarketly.co/assets/images/uploads/D7PDjsGQq8xdkGm.png" width="150">
</a>
</p>


## CuraLife FB Event Import / Live Synchronisation Mechanism

FB Event Import System allow to import events into Facebook based on past orders in Shopify

Built on 
* [Laravel 8.x](https://laravel.com) - Web application framework
* [Orchid 11.x](https://orchid.software/) - Open source laravel admin panel builder
* [Laravel Shopify 1.6](https://github.com/clarity-tech/laravel-shopify) - Shopify API package for Laravel
* [Facebook Business SDK for PHP 13.0.x](https://github.com/facebook/facebook-php-business-sdk) - Includes Facebook Conversions API 
* [Laravel Log-to-DB 3.0.x](https://github.com/danielme85/laravel-log-to-db) - Log channel handler for DB

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

## Installation

1. Clone this repository 

2. Install packages via composer:
```shell
composer install
```

3. Create .env file based on the suggested structure
```shell
cp .env.example .env
```
4. Add app URL for use in webhooks
```
APP_URL=http://localhost
```
5. Set up database and add connection data into .env file
```
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=laravel
DB_USERNAME=root
DB_PASSWORD=
```

6. Run database migrations
```shell
php artisan migrate
```
7. Fill FB credentials in .env file
```
FB_PIXEL_ID=
FB_ACCESS_TOKEN=
FB_TEST_EVENT_CODE=
```
8. Add Shopify credentials to .env file. <br>
By default, the system operates on 2 stores, which are set up in config/services.php inside 'shopify' array.
   <br><br>
It is possible to add more stores by adding more credential groups into config/services.php and adding new values to .env file.
```
SHOPIFY_US_HOST=
SHOPIFY_US_TOKEN=
SHOPIFY_US_API_KEY=
SHOPIFY_US_API_SECRET=
```
9. Add import identification key for the file import.
The key is used to identify which store the order is coming from to be able pull more details it via API.
```
SHOPIFY_US_IMPORT_KEY=
```

10. Create admin credentials in Orchid 
```shell
php artisan orchid:admin admin admin@admin.com password
```


## Import modes

The implementation supports 2 modes for importing events into facebook
* Batch (recommended) - events are split into batches (up to 1000) and sent together in a batch. <br> This mode only needs to send a request per one batch, but if one event has incompatible data, the whole batch will not go through.
* Async - event are sent one by one asynchronously, if one event fails, all other events will not be affected, but more requests are made

The batch mode is recommended for import, but if for some reason the batch import is failing, async mode might be able to process it.

The default number of events to be sent per batch is 1000, since it's the maximum allowed by Facebook.
This can be customised in config/contants.php:

```
'event_batch_limit_facebook' => 1000,
```

## License

All packages used in the system are open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
