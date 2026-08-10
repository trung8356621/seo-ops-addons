<?php

declare(strict_types=1);

namespace Omnichannel\Addons\Media\Console;

use Omnichannel\Addons\Commerce\Services\ProductGallery\ProductGalleryPromptsDoctorService;
use Illuminate\Console\Command;

final class ProductGalleryPromptsDoctorCommand extends Command
{
    protected $signature = 'seo:product-gallery-prompts-doctor';

    protected $description = 'Validate Mode 2 Prompt Hook bindings, variables, compile, and fallback-brief policy.';

    public function handle(ProductGalleryPromptsDoctorService $doctor): int
    {
        $report = $doctor->diagnose();

        foreach ($report['lines'] as $line) {
            $pad = str_pad($line['label'].' ', 40, '.', STR_PAD_RIGHT);
            $this->line($pad.' '.$line['status'].($line['detail'] !== '' ? ' ('.$line['detail'].')' : ''));
        }

        if (! $report['ok']) {
            $this->newLine();
            $this->error('Product gallery prompts doctor FAILED');
            foreach ($report['errors'] as $error) {
                $this->line(' - '.$error);
            }

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Product gallery prompts doctor OK');

        return self::SUCCESS;
    }
}
