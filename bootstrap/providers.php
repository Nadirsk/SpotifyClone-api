<?php

declare(strict_types=1);

use App\Providers\AppServiceProvider;
use App\Providers\AuthServiceProvider;
use App\Providers\ProviderIntegrationServiceProvider;
use App\Providers\RepositoryServiceProvider;
use App\Providers\SearchServiceProvider;

return [
    AppServiceProvider::class,
    AuthServiceProvider::class,
    RepositoryServiceProvider::class,
    SearchServiceProvider::class,
    ProviderIntegrationServiceProvider::class,
];
