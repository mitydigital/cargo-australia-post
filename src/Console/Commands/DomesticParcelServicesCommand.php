<?php

namespace MityDigital\CargoAustraliaPost\Console\Commands;

use Illuminate\Console\Command;
use MityDigital\CargoAustraliaPost\Exceptions\AustraliaPostApiException;
use MityDigital\CargoAustraliaPost\Services\AustraliaPostService;
use Statamic\Console\RunsInPlease;

class DomesticParcelServicesCommand extends Command
{
    use RunsInPlease;

    protected $signature = 'cargo-australia-post:domestic-parcel-services {toPostcode : The destination postcode}';

    protected $description = 'List the Australia Post Domestic Parcel Services';

    public function handle(AustraliaPostService $service): int
    {
        try {
            $availableServices = $service->getDomesticServices($this->argument('toPostcode'));
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
