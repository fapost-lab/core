<?php

declare(strict_types=1);

namespace App\Domains\Solution\Contracts;

use App\Domains\Solution\DTO\InstalledConnector;
use Fapost\Foundation\Connector\ConnectorRegistrarInterface;

/**
 * Gives one connector the registrar it registers its channels through.
 *
 * Made per connector so that what it registers can be checked against that connector's manifest. The implementation
 * lives outside this domain because it writes into the registries of the domains a connector extends.
 */
interface ConnectorRegistrarFactoryInterface
{
    public function forConnector(InstalledConnector $connector): ConnectorRegistrarInterface;

    /**
     * Closes registration once every connector has been given its registrar.
     */
    public function freeze(): void;
}
