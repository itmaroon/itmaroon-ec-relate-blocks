<?php

namespace Itmar\ShopifyClassPackage\Bootstrap;

use Itmar\ShopifyClassPackage\Interface\Rest\CartController;
use Itmar\ShopifyClassPackage\Interface\Rest\CustomerController;
use Itmar\ShopifyClassPackage\Interface\Rest\ProductController;
use Itmar\ShopifyClassPackage\Interface\Rest\SettingsController;
use Itmar\ShopifyClassPackage\Infrastructure\Queue\CommerceQueue;
use Itmar\ShopifyClassPackage\Interface\Admin\CommerceSyncPage;
use Itmar\ShopifyClassPackage\Support\ShopifyApi;

if (! defined('ABSPATH')) exit;

final class Plugin
{
    public function boot(): void
    {
        ShopifyApi::registerMonitoring();
        CommerceQueue::instance()->registerHooks();
        (new CommerceSyncPage())->register();

        add_action('init', function () {
            (new CustomerController())->registerAjax();
            $productController = new ProductController();
            $productController->registerWpHooks();
            $productController->registerQueueHandler();
        });

        add_action('rest_api_init',  function () {
            (new CartController())->registerRest();
            (new CustomerController())->registerRest();
            (new ProductController())->registerRest();
            (new SettingsController())->register();
        }, 10);
    }
}
