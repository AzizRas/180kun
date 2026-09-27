<?php
declare(strict_types=1);

namespace Modules\Media\Domain;

use App\Result;

/**
 * Обработка фото: проверка, поворот, пересжатие, два размера.
 *
 * Исходный файл не хранится никогда. Из него рисуется новая картинка
 * JPEG — и вместе с пересжатием пропадают все метаданные: координаты GPS,
 * модель телефона, время съёмки. Это главная причина пересжимать, а не
 * «просто сохранить, что прислали».
 *
 * Движок — GD: он есть в любой сборке PHP и на любом хостинге, и этот
 * путь покрыт тестами. ImageMagick не нужен.
 */
final class Images
{
    /** Защита от «бомбы распаковки»: маленький файл на 30 000 × 30 000 точек. */
    public const MAX_PIXELS = 40_000_000;
    public const MAX_SIDE   = 12_000;

    public static function engine(): ?string
    {
        return extension_loaded('gd') && function_exists('imagecreatefromstring') && function_exists('imagejpeg')
            ? 'gd'
            : null;
    }

    /** Тип по первым байтам, а не по имени файла и не по заголовку браузера. */
    public static function sniff(string $bytes): ?string
    {
        if (strncmp($bytes, "\xFF\xD8\xFF", 3) === 0) {
            return 'jpeg';
        }
        if (strncmp($bytes, "\x89PNG\r\n\x1a\n", 8) === 0) {
            return 'png';
        }
        if (strlen($bytes) > 12 && substr($bytes, 0, 4) === 'RIFF' && substr($bytes, 8, 4) === 'WEBP') {
            return 'webp';
        }
        return null;
    }

    /**
     * @return Result ok: {full: string, thumb: string, width: int, height: int, thumb_width: int, thumb_height: int}
     *                fail: bad_image | too_many_pixels | no_engine
     */
    public static function process(string $bytes, int $maxSide = 1600, int $thumbSide = 480): Result
    {
        if (self::engine() === null) {
            return Result::fail('no_engine');
        }
        $type = self::sniff($bytes);
        if ($type === null || ($type === 'webp' && !function_exists('imagecreatefromwebp'))) {
            return Result::fail('bad_image');
        }

        // Размер читается из заголовка, без распаковки — до того, как
        // выделять память под пиксели.
        $size = @getimagesizefromstring($bytes);
        if ($size === false || $size[0] < 16 || $size[1] < 16) {
            return Result::fail('bad_image');
        }
        if ($size[0] * $size[1] > self::MAX_PIXELS || max($size[0], $size[1]) > self::MAX_SIDE) {
            return Result::fail('too_many_pixels');
        }

        $src = @imagecreatefromstring($bytes);
        if ($src === false) {
            return Result::fail('bad_image');
        }
        if ($type === 'jpeg') {
            $src = self::orient($src, self::exifOrientation($bytes));
        }

        $w = imagesx($src);
        $h = imagesy($src);
        [$full, $fw, $fh]  = self::jpeg($src, $w, $h, $maxSide, 82);
        [$thumb, $tw, $th] = self::jpeg($src, $w, $h, $thumbSide, 75);
        imagedestroy($src);

        return Result::ok([
            'full' => $full, 'width' => $fw, 'height' => $fh,
            'thumb' => $thumb, 'thumb_width' => $tw, 'thumb_height' => $th,
        ]);
    }

    /** @return array{0: string, 1: int, 2: int} */
    private static function jpeg(\GdImage $src, int $w, int $h, int $side, int $quality): array
    {
        $scale = min(1.0, $side / max($w, $h));
        $nw    = max(1, (int) round($w * $scale));
        $nh    = max(1, (int) round($h * $scale));

        $dst = imagecreatetruecolor($nw, $nh);
        // Прозрачный PNG на белом, а не на чёрном.
        imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255));
        imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
        imageinterlace($dst, true);

        ob_start();
        imagejpeg($dst, null, $quality);
        $out = (string) ob_get_clean();
        imagedestroy($dst);
        return [$out, $nw, $nh];
    }

    /**
     * Поворот по метке EXIF. Телефон пишет пиксели «как лежала матрица»
     * и метку «поверни на 90°»; после пересжатия метки не будет, поэтому
     * поворачиваем сами — иначе фото из зала ляжет на бок.
     */
    private static function orient(\GdImage $img, int $o): \GdImage
    {
        if ($o === 2 || $o === 5 || $o === 7) {
            imageflip($img, IMG_FLIP_HORIZONTAL);
        }
        $angle = match ($o) {
            3, 4 => 180,
            5, 8 => 90,     // imagerotate крутит против часовой
            6, 7 => -90,
            default => 0,
        };
        if ($o === 4) {
            // 4 = отражение по вертикали = отражение по горизонтали + 180°
            imageflip($img, IMG_FLIP_HORIZONTAL);
        }
        if ($angle !== 0) {
            $rotated = imagerotate($img, $angle, 0);
            if ($rotated !== false) {
                imagedestroy($img);
                $img = $rotated;
            }
        }
        return $img;
    }

    /**
     * Метка ориентации из блока EXIF (тег 0x0112) — без расширения exif,
     * которого на хостинге может не быть. Читается только IFD0.
     */
    public static function exifOrientation(string $jpeg): int
    {
        $len = strlen($jpeg);
        $pos = 2;
        while ($pos + 4 <= $len && $jpeg[$pos] === "\xFF") {
            $marker  = ord($jpeg[$pos + 1]);
            $segment = unpack('n', substr($jpeg, $pos + 2, 2))[1] ?? 0;
            if ($marker === 0xDA || $segment < 2) {
                break;   // дальше пиксели
            }
            if ($marker === 0xE1 && substr($jpeg, $pos + 4, 6) === "Exif\0\0") {
                $tiff = substr($jpeg, $pos + 10, $segment - 8);
                return self::orientationFromTiff($tiff);
            }
            $pos += 2 + $segment;
        }
        return 1;
    }

    private static function orientationFromTiff(string $t): int
    {
        if (strlen($t) < 8) {
            return 1;
        }
        $le    = substr($t, 0, 2) === 'II';
        $u16   = static fn(int $at): int => (int) (unpack($le ? 'v' : 'n', substr($t, $at, 2))[1] ?? 0);
        $u32   = static fn(int $at): int => (int) (unpack($le ? 'V' : 'N', substr($t, $at, 4))[1] ?? 0);
        $ifd   = $u32(4);
        if ($ifd + 2 > strlen($t)) {
            return 1;
        }
        $count = $u16($ifd);
        for ($i = 0; $i < $count; $i++) {
            $entry = $ifd + 2 + $i * 12;
            if ($entry + 12 > strlen($t)) {
                break;
            }
            if ($u16($entry) === 0x0112) {
                $v = $u16($entry + 8);
                return $v >= 1 && $v <= 8 ? $v : 1;
            }
        }
        return 1;
    }
}
