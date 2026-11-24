<?php
/**
 * PassportOcrService.php
 * -------------------------------------------------------
 * Optional OCR bridge. Tesseract is only used when the binary
 * is actually installed; otherwise the service reports an
 * explicit "unavailable" status and the officer can paste the
 * MRZ manually. OCR is never treated as identity verification.
 * -------------------------------------------------------
 */

class PassportOcrService
{
    private string $binary;
    private MrzParser $parser;

    public function __construct(?string $binary = null)
    {
        $this->binary = $binary ?? (string) (getenv('TESSERACT_BIN') ?: 'tesseract');
        $this->parser = new MrzParser();
    }

    /** @return array{available:bool,engine:string,version:?string,message:string} */
    public function status(): array
    {
        $resolved = $this->resolveBinary();

        if ($resolved === null) {
            return [
                'available' => false,
                'engine'    => 'tesseract',
                'version'   => null,
                'message'   => t('OCR is not installed on this server. Paste the two MRZ lines to read them safely, or type the passport details manually.'),
            ];
        }

        $version = null;
        $output  = [];
        @exec(escapeshellarg($resolved) . ' --version 2>&1', $output);
        if (!empty($output[0]) && preg_match('/tesseract\s+([0-9.]+)/i', $output[0], $m)) {
            $version = $m[1];
        }

        return [
            'available' => true,
            'engine'    => 'tesseract',
            'version'   => $version,
            'message'   => t('OCR is available. Always compare extracted values with the passport and let the officer confirm them.'),
        ];
    }

    /**
     * Run OCR on a private image and parse the MRZ.
     *
     * @return array{status:string,engine:string,version:?string,raw_text:string,parsed:?array,error:?string}
     */
    public function extract(string $imagePath): array
    {
        $status = $this->status();

        if (!$status['available']) {
            return [
                'status'   => 'unavailable',
                'engine'   => 'tesseract',
                'version'  => null,
                'raw_text' => '',
                'parsed'   => null,
                'error'    => $status['message'],
            ];
        }

        if (!is_file($imagePath) || !is_readable($imagePath)) {
            return [
                'status'   => 'failed',
                'engine'   => 'tesseract',
                'version'  => $status['version'],
                'raw_text' => '',
                'parsed'   => null,
                'error'    => t('The passport image could not be read from private storage.'),
            ];
        }

        $binary = $this->resolveBinary();
        $cmd = 'timeout 30 ' . escapeshellarg((string) $binary)
             . ' ' . escapeshellarg($imagePath)
             . ' stdout --psm 6 -l eng 2>/dev/null';

        $raw = [];
        $code = 0;
        @exec($cmd, $raw, $code);

        $text   = implode("\n", $raw);
        $parsed = $this->parser->parse($text);

        return [
            'status'   => $code === 0 ? ($parsed['valid'] ? 'valid' : 'invalid') : 'failed',
            'engine'   => 'tesseract',
            'version'  => $status['version'],
            'raw_text' => $text,
            'parsed'   => $parsed,
            'error'    => $code === 0 ? null : t('The OCR engine could not read this image. Try a sharper photograph or paste the MRZ.'),
        ];
    }

    /** Parse MRZ text pasted by an officer. */
    public function parseText(string $text): array
    {
        $parsed = $this->parser->parse($text);

        return [
            'status'   => $parsed['valid'] ? 'valid' : 'invalid',
            'engine'   => 'mrz-parser',
            'version'  => APP_VERSION,
            'raw_text' => $text,
            'parsed'   => $parsed,
            'error'    => $parsed['valid'] ? null : implode(' ', $parsed['errors']),
        ];
    }

    /** Persist an extraction attempt against a document. */
    public function record(int $documentId, array $result, ?int $officerId = null): void
    {
        $db = Database::getConnection();

        $db->prepare(
            "INSERT INTO document_ocr_results
                (document_id, engine, engine_version, raw_text, parsed_json,
                 validation_status, confidence, error_message)
             VALUES
                (:document, :engine, :version, :raw, :parsed,
                 :status, :confidence, :error)"
        )->execute([
            'document'   => $documentId,
            'engine'     => mb_substr((string) ($result['engine'] ?? 'mrz-parser'), 0, 40),
            'version'    => $result['version'] ?? null,
            'raw'        => $result['raw_text'] ?? '',
            'parsed'     => json_encode($result['parsed'] ?? null, JSON_UNESCAPED_UNICODE),
            'status'     => in_array($result['status'] ?? '', ['valid', 'invalid', 'unavailable', 'manual'], true)
                ? $result['status'] : 'pending',
            'confidence' => isset($result['parsed']['confidence']) ? (float) $result['parsed']['confidence'] : null,
            'error'      => mb_substr((string) ($result['error'] ?? ''), 0, 255) ?: null,
        ]);

        $ocrStatus = match ($result['status'] ?? '') {
            'valid'       => 'done',
            'invalid'     => 'failed',
            'unavailable' => 'unavailable',
            default       => 'none',
        };

        $db->prepare("UPDATE customer_documents SET ocr_status = :s WHERE id = :id")
           ->execute(['s' => $ocrStatus, 'id' => $documentId]);

        AuditLog::record('customer_document.ocr', 'customer_documents', $documentId,
            'OCR/MRZ read attempt: ' . ($result['status'] ?? 'unknown'));
    }

    private function resolveBinary(): ?string
    {
        if ($this->binary === '' || str_contains($this->binary, "\0")) {
            return null;
        }

        if (str_contains($this->binary, '/') && is_executable($this->binary)) {
            return $this->binary;
        }

        $found = [];
        $code  = 1;
        @exec('command -v ' . escapeshellarg($this->binary) . ' 2>/dev/null', $found, $code);

        if ($code === 0 && !empty($found[0]) && is_executable($found[0])) {
            return $found[0];
        }

        return null;
    }
}
