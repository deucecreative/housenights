<?php

declare(strict_types=1);

namespace HiEvents\Console\Commands;

use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Console\Command;
use Throwable;

class InstallOutfitFontCommand extends Command
{
    protected $signature = 'fonts:install-outfit';

    protected $description = 'Register Outfit TTF weights with DOMPDF (generates .ufm cache files in storage/fonts/).';

    /**
     * Weights we ship in storage/fonts/. CSS font-weight on the left, file suffix on the right.
     */
    private const WEIGHTS = [
        400 => 'outfit-400.ttf',
        500 => 'outfit-500.ttf',
        600 => 'outfit-600.ttf',
        700 => 'outfit-700.ttf',
    ];

    public function handle(): int
    {
        $fontsDir = storage_path('fonts');
        $dompdf = Pdf::getDomPDF();
        $fontMetrics = $dompdf->getFontMetrics();

        foreach (self::WEIGHTS as $weight => $filename) {
            $path = $fontsDir . DIRECTORY_SEPARATOR . $filename;
            if (!is_file($path)) {
                $this->error("Missing font file: {$path}");
                return self::FAILURE;
            }

            try {
                $fontMetrics->registerFont(
                    ['family' => 'outfit', 'weight' => $weight, 'style' => 'normal'],
                    $path,
                );
                $this->info("Registered outfit weight {$weight} from {$filename}");
            } catch (Throwable $e) {
                $this->error("Failed to register {$filename}: " . $e->getMessage());
                return self::FAILURE;
            }
        }

        $this->newLine();
        $this->info('Outfit font installed. Commit the new files under storage/fonts/.');
        return self::SUCCESS;
    }
}
