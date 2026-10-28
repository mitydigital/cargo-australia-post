<?php

namespace MityDigital\CargoAustraliaPost\Console\Commands;

use Illuminate\Console\Command;
use MityDigital\CargoAustraliaPost\Exceptions\AustraliaPostApiException;
use MityDigital\CargoAustraliaPost\Services\AustraliaPostService;
use Statamic\Console\RunsInPlease;

class InternationalParcelServicesCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'cargo-australia-post:international-parcel-services {countryCode : The destination country code}';

    protected $description = 'List the Australia Post International Parcel Services';

    public function handle(AustraliaPostService $service): int
    {
        try {
            $availableServices = $service->getInternationalServices($this->argument('countryCode'));
        } catch (AustraliaPostApiException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $services = [];

        foreach ($availableServices as $availableService) {
            $services[] = [
                'Code' => $availableService['code'],
                'Name' => $availableService['name'],
            ];
        }

        $this->table(['Code', 'Name'], $services);

        return self::SUCCESS;
    }
}
