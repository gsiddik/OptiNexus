<?php

namespace App\Console\Commands;

use App\Services\Gateway\OptiRadarConnector;
use Illuminate\Console\Command;

class SyncOptiRadarTelematics extends Command
{
    protected $signature = 'gateway:sync-optiradar';

    protected $description = 'Pull the latest device odometers from OptiRadar (Traccar) into the API Gateway';

    public function handle(OptiRadarConnector $connector): int
    {
        try {
            $r = $connector->sync();
        } catch (\Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->info("OptiRadar sync: {$r['devices']} devices, {$r['accepted']} new readings, {$r['duplicates']} unchanged, {$r['skipped']} skipped across {$r['tenants']} tenants.");

        return self::SUCCESS;
    }
}
