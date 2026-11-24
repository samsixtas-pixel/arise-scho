<?php
/**
 * PhotoStorage.php
 * -------------------------------------------------------
 * Accepts a camera capture (base64 data URL) or an uploaded
 * image, re-encodes it with GD and stores a normalised JPEG.
 *
 * Re-encoding is deliberate: it strips EXIF metadata and any
 * embedded payload, so a crafted file can never be executed
 * or used for stored XSS.
 * -------------------------------------------------------
 */

class PhotoStorage
{
    private const MAX_EDGE = 900;
    private const QUALITY  = 82;

    /**
     * Store a photo from a data URL (camera) or an uploaded file.
     *
     * @param string|null $dataUrl e.g. data:image/jpeg;base64,...
     * @param array|null  $file    $_FILES['photo'] entry
     * @return string|null relative path (uploads/customers/...) or null
     */
    public static function store(?string $dataUrl, ?array $file, string $folder = 'customers'): ?string
    {
        $binary = null;
        $source = null;

        if (is_string($dataUrl) && str_starts_with($dataUrl, 'data:image/')) {
            if (!preg_match('#^data:image/(jpeg|jpg|png|webp);base64,(.+)$#is', $dataUrl, $m)) {
                throw new ValidationException([t('The captured photo format is not supported.')]);
            }

            $binary = base64_decode($m[2], true);

            if ($binary === false) {
                throw new ValidationException([t('The captured photo could not be read.')]);
            }

            $source = 'camera';
        } elseif (is_array($file) && isset($file['tmp_name']) && $file['error'] !== UPLOAD_ERR_NO_FILE) {
            if ($file['error'] !== UPLOAD_ERR_OK) {
                throw new ValidationException([t('The photo upload failed. Please try again.')]);
            }

            if (($file['size'] ?? 0) > MAX_PHOTO_BYTES) {
                throw new ValidationException([t('The photo must be smaller than :n MB.', ['n' => 4])]);
            }

            if (!is_uploaded_file($file['tmp_name'])) {
                throw new ValidationException([t('The photo upload could not be verified.')]);
            }

            $binary = file_get_contents($file['tmp_name']);

            if ($binary === false) {
                throw new ValidationException([t('The photo could not be read.')]);
            }

            $source = 'upload';
        } else {
            return null;
        }

        if (strlen($binary) > MAX_PHOTO_BYTES) {
            throw new ValidationException([t('The photo must be smaller than :n MB.', ['n' => 4])]);
        }

        $info = @getimagesizefromstring($binary);

        if ($info === false || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
            throw new ValidationException([t('Only JPEG, PNG or WEBP images are accepted.')]);
        }

        $image = @imagecreatefromstring($binary);

        if ($image === false) {
            throw new ValidationException([t('The photo could not be processed.')]);
        }

        $width  = imagesx($image);
        $height = imagesy($image);

        if ($width < 120 || $height < 120) {
            imagedestroy($image);
            throw new ValidationException([t('The photo is too small. Please capture it again.')]);
        }

        $scale = min(1, self::MAX_EDGE / max($width, $height));

        if ($scale < 1) {
            $newW = max(1, (int) round($width * $scale));
            $newH = max(1, (int) round($height * $scale));

            $resized = imagecreatetruecolor($newW, $newH);
            $white   = imagecolorallocate($resized, 255, 255, 255);
            imagefill($resized, 0, 0, $white);
            imagecopyresampled($resized, $image, 0, 0, 0, 0, $newW, $newH, $width, $height);

            imagedestroy($image);
            $image = $resized;
        }

        $dir = PRIVATE_DIR . '/' . trim($folder, '/') . '/' . date('Y') . '/' . date('m');

        if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
            imagedestroy($image);
            throw new RuntimeException('Unable to create the upload directory.');
        }

        $filename = sprintf('%s-%s.jpg', date('YmdHis'), bin2hex(random_bytes(5)));
        $absolute = $dir . '/' . $filename;

        if (!imagejpeg($image, $absolute, self::QUALITY)) {
            imagedestroy($image);
            throw new RuntimeException('Unable to save the photo.');
        }

        imagedestroy($image);
        @chmod($absolute, 0644);

        return 'private/' . trim($folder, '/') . '/' . date('Y') . '/' . date('m') . '/' . $filename;
    }

    /** Delete a stored photo (ignores anything outside the private store). */
    public static function delete(?string $relativePath): void
    {
        if (empty($relativePath)) {
            return;
        }

        if (str_starts_with($relativePath, 'private/')) {
            $root = realpath(PRIVATE_DIR) ?: PRIVATE_DIR;
            $absolute = realpath(PRIVATE_DIR . '/' . substr($relativePath, strlen('private/')));

            if ($absolute && str_starts_with($absolute, $root)) {
                @unlink($absolute);
            }

            return;
        }

        // Legacy files written before photographs moved to private storage.
        if (!str_starts_with($relativePath, 'uploads/')) {
            return;
        }

        $absolute = realpath(APP_ROOT . '/' . $relativePath);

        if ($absolute && str_starts_with($absolute, realpath(UPLOAD_DIR) ?: UPLOAD_DIR)) {
            @unlink($absolute);
        }
    }

    /** Authenticated URL for a stored photo. */
    public static function url(?string $relativePath): ?string
    {
        if (empty($relativePath)) {
            return null;
        }

        $version = substr(md5($relativePath), 0, 6);

        if (str_starts_with($relativePath, 'private/')) {
            return BASE_URL . 'media.php?f=' . rawurlencode(substr($relativePath, strlen('private/')))
                 . '&v=' . $version;
        }

        return BASE_URL . ltrim($relativePath, '/') . '?v=' . $version;
    }

    /**
     * Small preview for tables and lists. media.php generates and
     * caches the thumbnail on first request, so a customer list
     * never downloads full portrait images.
     */
    public static function thumbUrl(?string $relativePath): ?string
    {
        $url = self::url($relativePath);

        if ($url === null || !str_starts_with((string) $relativePath, 'private/')) {
            return $url;
        }

        return $url . '&thumb=1';
    }
}
