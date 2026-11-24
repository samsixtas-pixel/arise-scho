<?php
/**
 * media.php — authorized delivery of private customer files.
 * -------------------------------------------------------
 * Supported requests:
 *   ?f=private-relative-path      legacy photos (signed-in users)
 *   ?doc=123&size=thumb|full      Customer Digital File (signed-in)
 *   ?version=45                   document version history (signed-in)
 *   ?asset=12&exp=…&sig=…         temporary phone capture (signed URL)
 *
 * No absolute filesystem path is ever accepted from the browser.
 * -------------------------------------------------------
 */

require_once __DIR__ . '/includes/autoload.php';

$docId     = (int) ($_GET['doc'] ?? 0);
$versionId = (int) ($_GET['version'] ?? 0);
$assetId   = (int) ($_GET['asset'] ?? 0);

$stream = static function (string $absolute, string $mime, string $disposition, string $downloadName = '', bool $cache = true): void {
    header('Content-Type: ' . $mime);
    header('Content-Length: ' . (string) filesize($absolute));
    header('X-Content-Type-Options: nosniff');
    header('Content-Security-Policy: default-src \'none\'; img-src \'self\'');
    header('Cache-Control: ' . ($cache ? 'private, max-age=3600' : 'no-store, private'));

    if ($disposition === 'attachment') {
        $safe = preg_replace('/[^A-Za-z0-9._\-]/', '-', $downloadName) ?: 'document.jpg';
        header('Content-Disposition: attachment; filename="' . $safe . '"');
    } else {
        header('Content-Disposition: inline');
    }

    readfile($absolute);
    exit;
};

// ------------------------------------------------------------------
// 1. Signed temporary camera asset (phone → PC preview)
// ------------------------------------------------------------------
if ($assetId > 0) {
    $exp = (int) ($_GET['exp'] ?? 0);
    $sig = (string) ($_GET['sig'] ?? '');

    if (!CameraSession::verifyAssetSignature($assetId, $exp, $sig)) {
        http_response_code(403);
        exit;
    }

    $stmt = Database::getConnection()->prepare(
        "SELECT * FROM camera_session_assets WHERE id = :id LIMIT 1"
    );
    $stmt->execute(['id' => $assetId]);
    $asset = $stmt->fetch();

    if (!$asset || !in_array($asset['processing_status'], ['uploaded', 'processed', 'accepted'], true)) {
        http_response_code(404);
        exit;
    }

    $absolute = DocumentStorage::absolutePath($asset['temporary_storage_key']);

    if ($absolute === null) {
        http_response_code(404);
        exit;
    }

    $stream($absolute, 'image/jpeg', 'inline', '', false);
}

// ------------------------------------------------------------------
// 2. Customer Digital File documents and version history
// ------------------------------------------------------------------
if ($docId > 0 || $versionId > 0) {
    User::requireLogin();

    $documentModel = new CustomerDocument();

    if ($versionId > 0) {
        $version = $documentModel->findVersion($versionId);

        if (!$version) {
            http_response_code(404);
            exit;
        }

        $absolute = DocumentStorage::absolutePath($version['storage_key']);

        if ($absolute === null) {
            http_response_code(404);
            exit;
        }

        $name = $version['customer_reference'] . '-' . $version['document_category']
              . '-v' . (int) $version['version_number'] . '.jpg';

        $stream($absolute, DocumentStorage::mimeFor($version['storage_key']), 'attachment', $name);
    }

    $document = $documentModel->findDetailed($docId);

    if (!$document || $document['document_status'] !== 'active') {
        http_response_code(404);
        exit;
    }

    $size = ($_GET['size'] ?? 'thumb') === 'full' ? 'full' : 'thumb';
    $key  = $size === 'thumb' && !empty($document['thumbnail_key'])
        ? $document['thumbnail_key']
        : ($document['storage_key'] ?: $document['path']);

    $absolute = DocumentStorage::absolutePath($key);

    if ($absolute === null && !empty($document['path'])) {
        // Pre-migration rows only have `path` and may live in the
        // old uploads/ or storage/ folders.
        $absolute = DocumentStorage::legacyAbsolutePath($document['path']);
    }

    if ($absolute === null) {
        http_response_code(404);
        exit;
    }

    $download = !empty($_GET['download']);
    $name     = $document['customer_reference'] . '-' . $document['document_category']
              . '-v' . (int) $document['version_number'] . '.jpg';

    if ($download) {
        AuditLog::record('customer_document.download', 'customer_documents', $docId,
            'Downloaded ' . CustomerDocument::label((string) $document['document_category'])
            . ' for ' . $document['customer_reference']);
    }

    $stream(
        $absolute,
        $document['mime_type'] ?: DocumentStorage::mimeFor($key),
        $download ? 'attachment' : 'inline',
        $name
    );
}

// ------------------------------------------------------------------
// 3. Legacy photo path (private/… only)
// ------------------------------------------------------------------
User::requireLogin();

$file = (string) ($_GET['f'] ?? '');

if (
    $file === ''
    || str_contains($file, "\0")
    || !preg_match('#^[A-Za-z0-9_\-/]+\.(?:jpe?g|png|webp)$#i', $file)
) {
    http_response_code(404);
    exit;
}

$root = realpath(PRIVATE_DIR);

if ($root === false) {
    http_response_code(404);
    exit;
}

$absolute = realpath($root . '/' . $file);

if (
    $absolute === false
    || !str_starts_with($absolute, $root . DIRECTORY_SEPARATOR)
    || !is_file($absolute)
) {
    http_response_code(404);
    exit;
}

$mime = match (strtolower(pathinfo($absolute, PATHINFO_EXTENSION))) {
    'png'  => 'image/png',
    'webp' => 'image/webp',
    default => 'image/jpeg',
};

// Small cached preview for customer lists. Generated once per file,
// never stored in the public web root.
if (!empty($_GET['thumb'])) {
    $cacheDir = PRIVATE_DIR . '/thumbnails/photos';
    $cache    = $cacheDir . '/' . hash('sha256', $absolute) . '.jpg';

    if (!is_file($cache) || filemtime($cache) < filemtime($absolute)) {
        if (!is_dir($cacheDir)) {
            @mkdir($cacheDir, 0750, true);
        }

        $source = is_dir($cacheDir) ? @imagecreatefromstring((string) @file_get_contents($absolute)) : false;

        if ($source !== false) {
            $width  = imagesx($source);
            $height = imagesy($source);
            $scale  = min(1, 240 / max(1, max($width, $height)));
            $tw     = max(1, (int) round($width * $scale));
            $th     = max(1, (int) round($height * $scale));

            $thumb = imagecreatetruecolor($tw, $th);
            $white = imagecolorallocate($thumb, 255, 255, 255);
            imagefill($thumb, 0, 0, $white);
            imagecopyresampled($thumb, $source, 0, 0, 0, 0, $tw, $th, $width, $height);

            @imagejpeg($thumb, $cache, 78);
            imagedestroy($thumb);
            imagedestroy($source);
            @chmod($cache, 0640);
        }
    }

    if (is_file($cache)) {
        $stream($cache, 'image/jpeg', 'inline');
    }
}

$stream($absolute, $mime, 'inline');
