<?php

namespace ErnestDefoe\Depot\Storage;

use Flarum\Foundation\ValidationException;
use Illuminate\Contracts\Filesystem\Factory as Filesystem;
use Illuminate\Contracts\Filesystem\Cloud;
use Illuminate\Contracts\Filesystem\Filesystem as Disk;
use Psr\Http\Message\UploadedFileInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Where the bytes live.
 *
 * Files go on a PRIVATE disk under the SHA-256 of their contents, so nothing
 * in a stored path comes from the upload — no name to escape, traverse or
 * overwrite — and the only way to a file is the download route, which checks
 * permission and counts. Screenshots go on a public disk, re-encoded, so what
 * is served is an image Depot drew rather than whatever was uploaded.
 *
 * Both disks are declared in extend.php, so a forum can point either at S3 or
 * R2 by overriding the driver.
 */
class Store
{
    public const FILES = 'depot-files';
    public const MEDIA = 'depot-media';

    /**
     * The most pixels a screenshot may claim. Decoding allocates width ×
     * height × 4 bytes whatever the file size, so a 700 KB PNG that claims
     * 16000×16000 would ask for a gigabyte. 40 megapixels is twice an 8K frame.
     */
    public const MAX_PIXELS = 40_000_000;

    /** Screenshot renditions: the long edge in pixels. */
    public const SHOT_FULL = 1920;
    public const SHOT_THUMB = 640;

    public function __construct(private Filesystem $filesystem, private TranslatorInterface $translator)
    {
    }

    private function t(string $key, array $params = []): string
    {
        return $this->translator->trans("ernestdefoe-depot.api.$key", $params);
    }

    public function files(): Disk
    {
        return $this->filesystem->disk(self::FILES);
    }

    /** Public, so it is a Cloud disk: it has URLs. */
    public function media(): Cloud
    {
        /** @var Cloud */
        return $this->filesystem->disk(self::MEDIA);
    }

    private static function fan(string $hash): string
    {
        return substr($hash, 0, 2).'/'.substr($hash, 2, 2).'/'.$hash;
    }

    /**
     * Keep an uploaded file.
     *
     * @return array{path: string, sha256: string, size: int, filename: string, mime: string}
     */
    public function putFile(UploadedFileInterface $upload, int $maxBytes): array
    {
        $this->assertUploaded($upload, 'file', $maxBytes);

        $tmp = tempnam(sys_get_temp_dir(), 'depot');
        $upload->moveTo($tmp);

        try {
            $sha = hash_file('sha256', $tmp);
            $size = (int) filesize($tmp);
            $path = self::fan($sha);

            if (! $this->files()->exists($path)) {
                $stream = fopen($tmp, 'rb');
                $this->files()->writeStream($path, $stream);
                is_resource($stream) && fclose($stream);
            }
        } finally {
            @unlink($tmp);
        }

        return [
            'path' => $path,
            'sha256' => $sha,
            'size' => $size,
            'filename' => self::safeName($upload->getClientFilename() ?: 'download'),
            // Recorded for the page, never used to serve: downloads always go
            // out as application/octet-stream with an attachment disposition.
            'mime' => substr((string) $upload->getClientMediaType(), 0, 100) ?: 'application/octet-stream',
        ];
    }

    /**
     * Re-encode an uploaded screenshot at two sizes.
     *
     * Decoding and drawing it again strips EXIF (including GPS), drops any
     * payload riding along in the file, and refuses anything GD cannot read
     * as a picture.
     *
     * @return array{path: string, width: int, height: int}
     */
    public function putScreenshot(UploadedFileInterface $upload, int $maxBytes): array
    {
        $bytes = $this->checkScreenshot($upload, $maxBytes);
        $image = @imagecreatefromstring($bytes);

        if (! $image) {
            throw new ValidationException(['screenshot' => $this->t('not_an_image')]);
        }

        $base = self::fan(hash('sha256', $bytes));
        [$w, $h] = [imagesx($image), imagesy($image)];

        foreach (['' => self::SHOT_FULL, '-thumb' => self::SHOT_THUMB] as $suffix => $edge) {
            $scale = min(1, $edge / max($w, $h));
            $copy = imagecreatetruecolor(max(1, (int) round($w * $scale)), max(1, (int) round($h * $scale)));
            imagecopyresampled($copy, $image, 0, 0, 0, 0, imagesx($copy), imagesy($copy), $w, $h);

            ob_start();
            imagewebp($copy, null, 82);
            $this->media()->put("$base$suffix.webp", (string) ob_get_clean());
            imagedestroy($copy);
        }

        imagedestroy($image);

        return ['path' => $base, 'width' => $w, 'height' => $h];
    }

    /**
     * Everything that can be known about a screenshot without decoding it:
     * it arrived, it is small enough, it is a picture type GD reads, and its
     * header does not claim more pixels than MAX_PIXELS. Run on every upload
     * before anything is saved, so a refusal leaves nothing half-made.
     */
    public function checkScreenshot(UploadedFileInterface $upload, int $maxBytes): string
    {
        $this->assertUploaded($upload, 'screenshot', $maxBytes);

        $stream = $upload->getStream();
        $stream->rewind();
        $bytes = (string) $stream;
        $info = @getimagesizefromstring($bytes);

        if (! $info || ! in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) {
            throw new ValidationException(['screenshot' => $this->t('not_an_image')]);
        }
        if ($info[0] * $info[1] > self::MAX_PIXELS) {
            throw new ValidationException(['screenshot' => $this->t('image_too_big', ['max' => intdiv(self::MAX_PIXELS, 1_000_000)])]);
        }

        return $bytes;
    }

    public function screenshotUrl(string $base, bool $thumb = false): string
    {
        return $this->media()->url($base.($thumb ? '-thumb' : '').'.webp');
    }

    public function deleteScreenshot(string $base): void
    {
        $this->media()->delete([$base.'.webp', $base.'-thumb.webp']);
    }

    private function assertUploaded(UploadedFileInterface $upload, string $field, int $maxBytes): void
    {
        if ($upload->getError() !== UPLOAD_ERR_OK) {
            throw new ValidationException([$field => $upload->getError() === UPLOAD_ERR_INI_SIZE || $upload->getError() === UPLOAD_ERR_FORM_SIZE
                ? $this->t('too_large_server')
                : $this->t('upload_broken')]);
        }

        if ($upload->getSize() > $maxBytes) {
            throw new ValidationException([$field => $this->t('too_large', ['mb' => intdiv($maxBytes, 1048576)])]);
        }
    }

    /** A filename that is safe in a Content-Disposition header and on any disk. */
    public static function safeName(string $name): string
    {
        $name = basename(str_replace('\\', '/', $name));
        $name = preg_replace('/[^\w.\- ]+/u', '_', $name) ?? 'download';
        $name = trim($name, ' .');

        return mb_substr($name !== '' ? $name : 'download', 0, 150);
    }
}
