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

        // DOMPDF writes installed-fonts.json with whatever path was passed
        // to registerFont() — for us that's an absolute path. Absolute paths
        // baked into a committed JSON file break on every other machine.
        // Strip the storage/fonts prefix so the entries are relative
        // basenames, which DOMPDF resolves against `font_dir` at load time.
        $this->normalizeInstalledFontsJson($fontsDir);

        $this->newLine();
        $this->info('Outfit font installed. Commit the new files under storage/fonts/.');
        return self::SUCCESS;
    }

    private function normalizeInstalledFontsJson(string $fontsDir): void
    {
        $jsonPath = $fontsDir . DIRECTORY_SEPARATOR . 'installed-fonts.json';
        if (!is_file($jsonPath)) {
            return;
        }

        $contents = file_get_contents($jsonPath);
        if ($contents === false) {
            return;
        }

        $data = json_decode($contents, true);
        if (!is_array($data)) {
            return;
        }

        $prefix = $fontsDir . DIRECTORY_SEPARATOR;
        $changed = false;
        foreach ($data as $family => $weights) {
            if (!is_array($weights)) {
                continue;
            }
            foreach ($weights as $weight => $path) {
                if (is_string($path) && str_starts_with($path, $prefix)) {
                    $data[$family][$weight] = substr($path, strlen($prefix));
                    $changed = true;
                }
            }
        }

        if ($changed) {
            file_put_contents(
                $jsonPath,
                json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            );
        }
    }
}
