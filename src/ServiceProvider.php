<?php

namespace MityDigital\CargoAustraliaPost;

use Illuminate\Console\Command;
use MityDigital\CargoAustraliaPost\Console\Commands\DomesticParcelServicesCommand;
use MityDigital\CargoAustraliaPost\Console\Commands\InternationalParcelServicesCommand;
use MityDigital\CargoAustraliaPost\Services\AustraliaPost\AustraliaPostApiConnector;
use MityDigital\CargoAustraliaPost\Services\AustraliaPost\PostageApi;
use MityDigital\CargoAustraliaPost\Services\AustraliaPostService;
use MityDigital\CargoAustraliaPost\ShippingMethods\AustraliaPost;
use Statamic\Providers\AddonServiceProvider;
use Statamic\Statamic;

class ServiceProvider extends AddonServiceProvider
{
    protected $vite = [
        'input' => [
            'resources/js/addon.js',
        ],
        'publicDirectory' => 'resources/dist',
    ];

    protected $commands = [
        DomesticParcelServicesCommand::class,
        InternationalParcelServicesCommand::class,
    ];

    public function bootAddon(): void
    {
        $this->bootApi();

        AustraliaPost::register();

        Statamic::afterInstalled(function (Command $command): void {
            $command->call('vendor:publish', [
                '--tag' => 'cargo-australia-post-config',
            ]);
        });
    }

    protected function bootApi(): void
    {
        $this->app->scoped(AustraliaPostApiConnector::class, function (): AustraliaPostApiConnector {
            return new AustraliaPostApiConnector(
                (string) config('cargo-australia-post.api_key', '')
            );
        });

        $this->app->scoped(PostageApi::class);
        $this->app->scoped(AustraliaPostService::class);
    }
}
