<?php
/**
 * DocumentStorage.php
 * -------------------------------------------------------
 * Validates, re-encodes and stores identity images in private
 * storage. Every image is decoded with GD and written back as
 * a fresh JPEG, so EXIF payloads, scripts and original file
 * names never reach the filesystem.
 *
 * Storage keys look like:
 *   private/customers/CUST-0001/identity/9f3c....jpg
 *   private/thumbnails/customers/CUST-0001/9f3c....jpg
 *   private/temporary/camera_sessions/<uuid>/9f3c....jpg
 * -------------------------------------------------------
 */

class DocumentStorage
{
    public const MAX_BYTES        = 10 * 1024 * 1024;   // 10 MB upload ceiling
    public const MAX_INPUT_EDGE   = 12000;              // refuse decompression bombs
    public const MIN_INPUT_EDGE   = 120;
    public const THUMB_EDGE       = 360;

    /**
     * Store an uploaded file.
     *
     * @param array $file $_FILES entry
     * @return array{storage_key:string,thumbnail_key:string,width:int,height:int,bytes:int,checksum:string,mime:string,original_filename:string}
     */
    public static function storeUpload(array $file, string $folder, int $maxEdge = 2000, int $quality = 85): array
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
            throw new ValidationException([t('Choose an image to upload.')]);
        }

        if (($file['error'] ?? UPLOAD_ERR_OK) !== UPLOAD_ERR_OK) {
            throw new ValidationException([t('The image upload failed. Please try again.')]);
        }

        if (($file['size'] ?? 0) > self::MAX_BYTES) {
            throw new ValidationException([t('The image must be smaller than :n MB.', ['n' => 10])]);
        }

        if (!is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            throw new ValidationException([t('The image upload could not be verified.')]);
        }

        $binary = file_get_contents((string) $file['tmp_name']);

        if ($binary === false) {
            throw new ValidationException([t('The image could not be read.')]);
        }

        return self::storeBinary($binary, $folder, $maxEdge, $quality, (string) $file['tmp_name'],
            (string) ($file['name'] ?? ''));
    }

    /**
     * Store a camera data URL (data:image/jpeg;base64,...).
     */
    public static function storeDataUrl(string $dataUrl, string $folder, int $maxEdge = 1200, int $quality = 86): array
    {
        if (!preg_match('#^data:image/(jpeg|jpg|png|webp);base64,(.+)$#is', $dataUrl, $m)) {
            throw new ValidationException([t('The captured image format is not supported.')]);
        }

        $binary = base64_decode($m[2], true);

        if ($binary === false || $binary === '') {
            throw new ValidationException([t('The captured image could not be read.')]);
        }

        return self::storeBinary($binary, $folder, $maxEdge, $quality);
    }

    /**
     * Store a decoded image and its thumbnail.
     *
     * @return array{storage_key:string,thumbnail_key:string,width:int,height:int,bytes:int,checksum:string,mime:string,original_filename:string}
     */
    public static function storeBinary(
        string $binary,
        string $folder,
        int $maxEdge = 2000,
        int $quality = 85,
        ?string $exifPath = null,
        string $originalFilename = ''
    ): array {
        if (strlen($binary) === 0 || strlen($binary) > self::MAX_BYTES) {
            throw new ValidationException([t('The image must be smaller than :n MB.', ['n' => 10])]);
        }

        $info = @getimagesizefromstring($binary);

        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_GIF], true)) {
            throw new ValidationException([t('Only JPEG or PNG images are accepted on this server.')]);
        }

        $width  = (int) $info[0];
        $height = (int) $info[1];

        if ($width < self::MIN_INPUT_EDGE || $height < self::MIN_INPUT_EDGE) {
            throw new ValidationException([t('The image is too small. Capture it again at a higher resolution.')]);
        }

        if ($width > self::MAX_INPUT_EDGE || $height > self::MAX_INPUT_EDGE) {
            throw new ValidationException([t('The image dimensions are too large.')]);
        }

        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            throw new ValidationException([t('The image could not be processed.')]);
        }

        // Honour EXIF orientation for ordinary uploads. Camera data URLs
        // reach us already rotated by the browser.
        if ($exifPath !== null && is_file($exifPath)) {
            $image = self::applyExifOrientation($image, $exifPath);
        }

        $image  = self::resizeToMaxEdge($image, $maxEdge);
        $width  = imagesx($image);
        $height = imagesy($image);

        $folder = self::safeFolder($folder);
        $base   = bin2hex(random_bytes(16));
        $dir    = PRIVATE_DIR . '/' . $folder;

        self::ensureDir($dir);

        $absolute = $dir . '/' . $base . '.jpg';

        if (!imagejpeg($image, $absolute, max(60, min(95, $quality)))) {
            imagedestroy($image);
            throw new RuntimeException('Unable to save the image.');
        }

        $thumbKey = self::makeThumbnail($image, $base, $folder);
        imagedestroy($image);
        @chmod($absolute, 0640);

        $bytes    = (int) filesize($absolute);
        $checksum = hash_file('sha256', $absolute);

        return [
            'storage_key'       => 'private/' . $folder . '/' . $base . '.jpg',
            'thumbnail_key'     => $thumbKey,
            'width'             => $width,
            'height'            => $height,
            'bytes'             => $bytes,
            'checksum'          => $checksum,
            'mime'              => 'image/jpeg',
            'original_filename' => mb_substr(preg_replace('/[^\p{L}\p{N}._\- ]/u', '', $originalFilename) ?? '', 0, 120),
        ];
    }

    /**
     * Temporary phone image, outside the customer document tree.
     */
    public static function storeTemporary(string $dataUrl, string $sessionUuid): array
    {
        $sessionUuid = preg_replace('/[^a-f0-9\-]/i', '', $sessionUuid) ?: 'session';

        return self::storeDataUrl(
            $dataUrl,
            'temporary/camera_sessions/' . $sessionUuid,
            1600,
            86
        );
    }

    /** Resolve a storage key to an absolute path inside private storage. */
    public static function absolutePath(?string $key): ?string
    {
        if (!$key || str_contains($key, "\0")) {
            return null;
        }

        $relative = str_starts_with($key, 'private/') ? substr($key, strlen('private/')) : $key;

        if (!preg_match('#^[A-Za-z0-9_\-/]+\.(?:jpe?g|png)$#i', $relative)) {
            return null;
        }

        $root = realpath(PRIVATE_DIR);

        if ($root === false) {
            return null;
        }

        $absolute = realpath(PRIVATE_DIR . '/' . $relative);

        if ($absolute === false || !str_starts_with($absolute, $root . DIRECTORY_SEPARATOR) || !is_file($absolute)) {
            return null;
        }

        return $absolute;
    }

    /** Delete a stored key (only inside the private store). */
    public static function deleteKey(?string $key): void
    {
        $absolute = self::absolutePath($key);

        if ($absolute !== null) {
            @unlink($absolute);
        }
    }

    /**
     * Resolve a pre-migration document path for delivery.
     *
     * Older rows only have the `path` column and may point at
     * `uploads/...` or `storage/...`. Only image files inside the
     * project's storage folders are accepted, and realpath() keeps
     * the result inside those folders.
     */
    public static function legacyAbsolutePath(?string $key): ?string
    {
        if (!$key || str_contains($key, "\0")) {
            return null;
        }

        $relative = ltrim(str_replace('\\', '/', $key), '/');

        if (!preg_match('#^[A-Za-z0-9_\-/]+\.(?:jpe?g|png|webp)$#i', $relative)) {
            return null;
        }

        $roots = [PRIVATE_DIR, APP_ROOT . '/storage'];

        // Old public uploads are resolved against the project root too.
        if (str_starts_with($relative, 'uploads/') || str_starts_with($relative, 'storage/')) {
            $roots[] = APP_ROOT;
        }

        foreach ($roots as $root) {
            $base = realpath($root);

            if ($base === false) {
                continue;
            }

            $absolute = realpath($base . '/' . $relative);

            if (
                $absolute !== false
                && str_starts_with($absolute, $base . DIRECTORY_SEPARATOR)
                && is_file($absolute)
                && in_array(
                    strtolower(pathinfo($absolute, PATHINFO_EXTENSION)),
                    ['jpg', 'jpeg', 'png', 'webp'],
                    true
                )
            ) {
                return $absolute;
            }
        }

        return null;
    }

    public static function mimeFor(?string $key): string
    {
        return match (strtolower(pathinfo((string) $key, PATHINFO_EXTENSION))) {
            'png' => 'image/png',
            default => 'image/jpeg',
        };
    }

    private static function makeThumbnail(GdImage $image, string $base, string $folder): string
    {
        $width  = imagesx($image);
        $height = imagesy($image);
        $scale  = min(1, self::THUMB_EDGE / max($width, $height));

        $tw = max(1, (int) round($width * $scale));
        $th = max(1, (int) round($height * $scale));

        $thumb = imagecreatetruecolor($tw, $th);
        $white = imagecolorallocate($thumb, 255, 255, 255);
        imagefill($thumb, 0, 0, $white);
        imagecopyresampled($thumb, $image, 0, 0, 0, 0, $tw, $th, $width, $height);

        // Thumbnails mirror the customer folder structure.
        $parts = explode('/', $folder);
        $docFolder = array_shift($parts) ?? 'customers';   // customers | temporary
        $rest      = implode('/', $parts);

        $thumbFolder = 'thumbnails/' . $docFolder . ($rest !== '' ? '/' . $rest : '');
        $thumbDir    = PRIVATE_DIR . '/' . trim($thumbFolder, '/');
        self::ensureDir($thumbDir);

        $absolute = $thumbDir . '/' . $base . '.jpg';

        if (!imagejpeg($thumb, $absolute, 78)) {
            imagedestroy($thumb);
            throw new RuntimeException('Unable to save the image thumbnail.');
        }

        imagedestroy($thumb);
        @chmod($absolute, 0640);

        return 'private/' . trim($thumbFolder, '/') . '/' . $base . '.jpg';
    }

    private static function resizeToMaxEdge(GdImage $image, int $maxEdge): GdImage
    {
        $width  = imagesx($image);
        $height = imagesy($image);
        $scale  = min(1, max(200, $maxEdge) / max($width, $height));

        if ($scale >= 1) {
            return $image;
        }

        $newW = max(1, (int) round($width * $scale));
        $newH = max(1, (int) round($height * $scale));

        $resized = imagecreatetruecolor($newW, $newH);
        $white   = imagecolorallocate($resized, 255, 255, 255);
        imagefill($resized, 0, 0, $white);
        imagecopyresampled($resized, $image, 0, 0, 0, 0, $newW, $newH, $width, $height);

        imagedestroy($image);

        return $resized;
    }

    private static function applyExifOrientation(GdImage $image, string $path): GdImage
    {
        if (!function_exists('exif_read_data')) {
            return $image;
        }

        $exif = @exif_read_data($path);

        if (!$exif || empty($exif['Orientation'])) {
            return $image;
        }

        $angle = match ((int) $exif['Orientation']) {
            3 => 180,
            6 => -90,
            8 => 90,
            default => 0,
        };

        if ($angle === 0) {
            return $image;
        }

        $rotated = imagerotate($image, $angle, 0);

        if ($rotated === false) {
            return $image;
        }

        imagedestroy($image);

        return $rotated;
    }

    private static function safeFolder(string $folder): string
    {
        $folder = trim($folder, '/');

        if (!preg_match('#^[A-Za-z0-9_\-/]+$#', $folder)) {
            throw new RuntimeException('Invalid document folder.');
        }

        return $folder;
    }

    private static function ensureDir(string $dir): void
    {
        if (is_dir($dir)) {
            return;
        }

        if (!@mkdir($dir, 0750, true) && !is_dir($dir)) {
            throw new RuntimeException('Unable to create the private storage folder.');
        }
    }
}
