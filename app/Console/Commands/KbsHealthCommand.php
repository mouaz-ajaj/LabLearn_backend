<?php

namespace App\Console\Commands;

use App\Services\Kbs\KbsClient;
use App\Services\Kbs\KbsException;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('kbs:health')]
#[Description('Check the standalone LabLearn KBS JSON service')]
class KbsHealthCommand extends Command
{
    public function handle(KbsClient $client): int
    {
        try {
            $health = $client->health();
            $metadata = $client->metadata();
        } catch (KbsException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info(sprintf(
            'KBS ready: engine %s, ruleset %s, categories %s',
            $health['engine_version'],
            $metadata['ruleset_version'],
            implode(', ', $metadata['supported_categories'] ?? []),
        ));

        return self::SUCCESS;
    }
}
