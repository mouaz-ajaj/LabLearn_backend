<?php

namespace App\Console\Commands;

use App\Services\Ocr\OcrClient;
use App\Services\Ocr\OcrException;
use Illuminate\Console\Command;

class OcrHealthCommand extends Command
{
    protected $signature = 'ocr:health';

    protected $description = 'Check connectivity to the configured OCR service';

    public function handle(OcrClient $ocrClient): int
    {
        try {
            $health = $ocrClient->health();
        } catch (OcrException $exception) {
            $this->error('OCR service is not reachable.');
            $this->line('URL: '.config('ocr.base_url'));
            $this->line('Error: '.$exception->errorCode);

            return self::FAILURE;
        }

        $this->info('OCR service is reachable.');
        $this->line('URL: '.config('ocr.base_url'));
        $this->line('Status: '.$health['status']);
        $this->line('Service: '.($health['service'] ?? 'unknown'));
        $this->line('Version: '.($health['version'] ?? 'unknown'));
        if (isset($health['device'])) {
            $this->line('Device: '.$health['device']);
        }

        return self::SUCCESS;
    }
}
