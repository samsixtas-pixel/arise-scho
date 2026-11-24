<?php
declare(strict_types=1);

namespace School\Services;

use RuntimeException;

/**
 * Server-side PDF rendering.
 *
 * Documents are authored as self-contained HTML and converted by the first
 * available headless engine: Chrome/Chromium (best CSS support) or WeasyPrint.
 * If neither binary is installed the caller can fall back to the browser's
 * print dialog; `available()` and `engine()` expose that state.
 */
final class PdfRenderer
{
    /** @var array<int,array{name:string,binary:string}> */
    private const CANDIDATES = [
        ['name' => 'chrome', 'binary' => 'google-chrome'],
        ['name' => 'chrome', 'binary' => 'google-chrome-stable'],
        ['name' => 'chromium', 'binary' => 'chromium'],
        ['name' => 'chromium', 'binary' => 'chromium-browser'],
        ['name' => 'weasyprint', 'binary' => 'weasyprint'],
    ];

    private ?array $engine = null;
    private bool $resolved = false;

    public function engine(): ?array
    {
        if ($this->resolved) {
            return $this->engine;
        }
        $this->resolved = true;
        foreach (self::CANDIDATES as $candidate) {
            $path = $this->locate($candidate['binary']);
            if ($path !== null) {
                $this->engine = ['name' => $candidate['name'], 'binary' => $path];
                break;
            }
        }
        return $this->engine;
    }

    public function available(): bool
    {
        return $this->engine() !== null;
    }

    public function engineName(): string
    {
        return (string) ($this->engine()['name'] ?? 'browser-print');
    }

    /**
     * Render HTML to PDF bytes.
     *
     * @param array{landscape?:bool,paper?:string,timeout?:int} $options
     */
    public function render(string $html, array $options = []): string
    {
        $engine = $this->engine();
        if ($engine === null) {
            throw new RuntimeException('No server-side PDF engine is installed. Use the browser print option instead.');
        }

        $directory = $this->workingDirectory();
        $token = bin2hex(random_bytes(8));
        $workDirectory = $directory . '/run-' . $token;
        if (!@mkdir($workDirectory, 0770, true) && !is_dir($workDirectory)) {
            throw new RuntimeException('Unable to create a PDF working directory.');
        }
        $htmlPath = $workDirectory . '/document.html';
        $pdfPath = $workDirectory . '/document.pdf';
        if (file_put_contents($htmlPath, $html) === false) {
            throw new RuntimeException('Unable to write the PDF source document.');
        }

        $timeout = max(15, (int) ($options['timeout'] ?? 90));
        $landscape = (bool) ($options['landscape'] ?? false);

        try {
            if ($engine['name'] === 'weasyprint') {
                $command = ['timeout', (string) $timeout, $engine['binary'], $htmlPath, $pdfPath];
            } else {
                $command = [
                    'timeout', (string) $timeout,
                    $engine['binary'],
                    '--headless=new',
                    '--no-sandbox',
                    '--disable-gpu',
                    '--disable-dev-shm-usage',
                    '--no-pdf-header-footer',
                    '--run-all-compositor-stages-before-draw',
                    '--virtual-time-budget=4000',
                    '--print-to-pdf=' . $pdfPath,
                    'file://' . $htmlPath,
                ];
                if ($landscape) {
                    // Chrome respects @page orientation; this flag is a fallback
                    // for engines that ignore it.
                    $command[] = '--landscape';
                }
            }

            $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
            $process = proc_open($command, $descriptors, $pipes, null, ['HOME' => $workDirectory, 'TMPDIR' => $workDirectory]);
            if (!is_resource($process)) {
                throw new RuntimeException('Unable to start the PDF engine.');
            }
            fclose($pipes[0]);
            stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exitCode = proc_close($process);

            if (!is_file($pdfPath) || filesize($pdfPath) < 100) {
                throw new RuntimeException('PDF generation failed: ' . trim((string) $error));
            }
            $bytes = (string) file_get_contents($pdfPath);
            if (!str_starts_with($bytes, '%PDF')) {
                throw new RuntimeException('The PDF engine returned an invalid document.');
            }
            return $bytes;
        } finally {
            @unlink($htmlPath);
            @unlink($pdfPath);
            $this->removeDirectory($workDirectory);
        }
    }

    /** Send a generated PDF to the browser. */
    public function stream(string $html, string $filename, bool $inline = false, array $options = []): void
    {
        $bytes = $this->render($html, $options);
        $safe = preg_replace('/[^A-Za-z0-9._-]+/', '-', $filename) ?: 'school-document.pdf';
        if (!str_ends_with(strtolower($safe), '.pdf')) {
            $safe .= '.pdf';
        }
        header('Content-Type: application/pdf');
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safe . '"');
        header('Content-Length: ' . strlen($bytes));
        header('Cache-Control: private, no-store, max-age=0');
        echo $bytes;
        exit;
    }

    private function locate(string $binary): ?string
    {
        $candidates = [];
        if (str_contains($binary, '/')) {
            $candidates[] = $binary;
        } else {
            $path = getenv('PATH') ?: '/usr/local/bin:/usr/bin:/bin:/opt/lampp/bin';
            foreach (explode(':', $path) as $directory) {
                if ($directory !== '') $candidates[] = rtrim($directory, '/') . '/' . $binary;
            }
            $candidates[] = '/usr/bin/' . $binary;
            $candidates[] = '/usr/local/bin/' . $binary;
        }
        foreach ($candidates as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }
        return null;
    }

    private function workingDirectory(): string
    {
        // Chrome creates a Unix-domain singleton socket inside HOME. Keep the
        // path short so it stays below the ~104 character socket limit.
        $base = rtrim(sys_get_temp_dir(), '/') . '/school-pdf';
        if (!is_dir($base) && !@mkdir($base, 0770, true) && !is_dir($base)) {
            $base = dirname(__DIR__, 2) . '/storage/school/pdf';
            if (!is_dir($base) && !@mkdir($base, 0770, true) && !is_dir($base)) {
                $base = sys_get_temp_dir();
            }
        }
        return $base;
    }

    private function removeDirectory(string $path): void
    {
        if (!is_dir($path)) {
            @unlink($path);
            return;
        }
        try {
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($items as $item) {
                if ($item->isDir()) {
                    @rmdir($item->getPathname());
                } else {
                    @unlink($item->getPathname());
                }
            }
        } catch (\Throwable $exception) {
            // Best-effort cleanup; never fail a document because cache files remain.
        }
        @rmdir($path);
    }
}
