<?php

namespace App\Console\Commands;

use App\Services\DataSourceRegistryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('hermes:sync-data-source-registry')]
#[Description('Synchronize canonical data source identities outside page rendering')]
class SyncDataSourceRegistryCommand extends Command
{
    public function handle(DataSourceRegistryService $registry): int
    {
        $result = $registry->syncRegistry();

        $this->info(sprintf(
            'Data source registry synchronized: created=%d updated=%d total=%d',
            $result['created'],
            $result['updated'],
            $result['total'],
        ));

        return self::SUCCESS;
    }
}
