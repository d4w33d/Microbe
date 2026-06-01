<?php

// =============================================================================
// ---{ Constants }-------------------------------------------------------------

define('MB_IMAGE_RESIZE_COVER',    'cover'   );
define('MB_IMAGE_RESIZE_CONTAINS', 'contains');
define('MB_IMAGE_RESIZE_ADJUST',   'adjust'  );

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Check if the given path extension corresponds to a valid image file.
 * @param  string $path       Path to the file.
 * @param  array  $extensions Array of allowed extensions.
 * @return bool               Is it a valid extension or not?
 */
function is_image_path(string $path, array $extensions = [ 'jpg', 'jpeg', 'png', 'gif' ]): bool
{
    return (bool) preg_match('/^.*\.(' . str_replace(',', '|', preg_quote(implode(',', $extensions), '/')) . ')$/', $path);
}

/**
 * <USER>
 * Returns raw data of a PNG image containing a single transparent pixel.
 * @return string PNG Data
 */
function get_pixel_image(): string
{
    return base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABAQMAAAAl21bKAAAABGdBTUEAALGPC/xhBQAAAAFzUkdCAK7OHOkAAAAJcEhZcwAALiMAAC4jAXilP3YAAAADUExURUdwTIL60tIAAAABdFJOUwBA5thmAAAACklEQVQI12NgAAAAAgAB4iG8MwAAAABJRU5ErkJggg==');
}

/**
 * <USER>
 * Resize an image.
 * @param  string      $src              Source image path.
 * @param  string|null $dst              Destination path. If null, nothing
 *                                       is written, and the Imagick instance
 *                                       is returned.
 * @param  int|null    $width            Destination width.
 *                                       If null, $width = $height.
 *                                       At least width or height must
 *                                       be provided.
 * @param  int|null    $height           Destination height.
 *                                       If null, $height = $width.
 *                                       At least width or height must
 *                                       be provided.
 * @param  string      $mode             Cropping mode (MB_IMAGE_RESIZE_*).
 * @param  int         $outputQuality    Output quality (0-100).
 * @param  mixed       $paddingColor     Padding color, when mode is "contains".
 * @param  bool        $mkdir            Make directories recursively if needed.
 * @param  string      $method           Image generation method: "imagick".
 * @return Imagick|null                  The Imagick instance, or null if image
 *                                       is written in directly to file.
 */
function resize_image(
    string  $src,
    ?string $dst              = null,
    ?int    $width            = null,
    ?int    $height           = null,
    string  $mode             = MB_IMAGE_RESIZE_COVER,
    int     $outputQuality    = 70,
    mixed   $paddingColor     = 'white',
    bool    $mkdir            = true,
    string  $method           = 'imagick',
): ?Imagick
{
    if ($method !== 'imagick') throw new Microbe_Exception("Method {$method} not supported");

    if ($height === null) $height = $width;
    if ($width === null) $width = $height;
    if (!$width) throw new Microbe_Exception("Missing width and height while trying to resize image");

    $im = new Imagick($src);
    $oldWidth = $im->getImageWidth();
    $oldHeight = $im->getImageHeight();

    $isSrcPng = is_file_extension($src, 'png');
    $isDstPng = !$dst || is_file_extension($dst, 'png');

    if ($mode === 'cover') {

        $newWidth = (int) (($height / $oldHeight) * $oldWidth);
        $newHeight = $height;
        $x = (int) (($newWidth - $width) / 2);
        $y = 0;
        if ($newWidth < $width) {
            $newWidth = $width;
            $newHeight = (int) (($width / $oldWidth) * $oldHeight);
            $x = 0;
            $y = (int) (($newHeight - $height) / 2);
        }

        $im->resizeImage($newWidth, $newHeight, Imagick::FILTER_LANCZOS, 0.9, true);
        $im->cropImage($width, $height, $x, $y);

    } else if ($mode === 'contains') {

        $backgroundColor = $isDstPng && $im->getImageAlphaChannel() ? new ImagickPixel('transparent') : new ImagickPixel($paddingColor);

        $im->thumbnailImage($width, $height, true);

        $x = (int) (($width - $im->getImageWidth()) / 2);
        $y = (int) (($height - $im->getImageHeight()) / 2);

        $n = new Imagick();
        $n->newImage($width, $height, $backgroundColor, $isDstPng ? 'png' : 'jpeg');
        $n->compositeImage($im, Imagick::COMPOSITE_OVER, $x, $y);

        $im = $n;

    } else if ($mode === 'adjust') {

        $newWidth = (int) (($height / $oldHeight) * $oldWidth);
        $newHeight = $height;
        if ($newWidth > $width) {
            $newWidth = $width;
            $newHeight = (int) (($width / $oldWidth) * $oldHeight);
        }

        $im->resizeImage($newWidth, $newHeight, Imagick::FILTER_LANCZOS, 0.9, true);

    } else throw new Microbe_Exception("Resizing mode {$mode} not supported");

    $im->setImageCompressionQuality($outputQuality);
    if (!$dst) return $im;
    if (!is_dir($dir = dirname($dst))) rmkdir($dir);
    $im->writeImage($dst);
    return null;
}

/**
 * <USER>
 * Read the image file with the proper content type.
 * It will guess automatically the content type.
 * @param  string  $path Image path
 */
function output_image_file(string $path): void
{
    $contentType = null;

    switch (get_file_extension($path, lower: true)) {
        case 'jpg':
        case 'jpeg': $contentType = 'image/jpeg'; break;
        case 'png':  $contentType = 'image/png';  break;
        case 'gif':  $contentType = 'image/gif';  break;
        case 'webp': $contentType = 'image/webp'; break;
        case 'bmp':  $contentType = 'image/bmp';  break;
        default: throw new Microbe_Exception("Unable to display image with unknown extension");
    }

    header('Content-Type: ' . $contentType);
    readfile($path);
    exit;
}

/**
 * <USER>
 * Load the GdImage instance from image path.
 * It will guess automatically the file type.
 * @param  string       $path Image path
 * @return GdImage|null       GdImage instance if success. Else, null.
 */
function image_create(string $path): ?GdImage
{
    switch (get_file_extension($path, lower: true)) {
        case 'jpg':
        case 'jpeg': return imagecreatefromjpeg($path);
        case 'png':  return imagecreatefrompng($path);
        case 'gif':  return imagecreatefromgif($path);
        case 'webp': return imagecreatefromwebp($path);
        case 'bmp':  return imagecreatefrombmp($path);
    }
    return null;
}

/**
 * <USER>
 * Save the GdImage to given path, guessing automatically the output file type.
 * @param  GdImage $im      GdImage instance.
 * @param  string  $path    Destination path.
 * @param  int     $quality Image quality if appliable.
 */
function image_save(GdImage $im, string $path, int $quality = 80): void
{
    switch (get_file_extension($path, lower: true)) {
        case 'jpg':
        case 'jpeg': imagejpeg($im, $path, $quality); break;
        case 'png':  imagepng($im, $path);            break;
        case 'gif':  imagegif($im, $path);            break;
        case 'webp': imagewebp($im, $path);           break;
        case 'bmp':  imagebmp($im, $path);            break;
    }
}

/**
 * <USER>
 * Resize an image and returns the path of the created file.
 * @param  string      $path             Path of source image.
 * @param  int|null    $width            Destination width.
 *                                       If null, $width = $height.
 *                                       At least width or height must
 *                                       be provided.
 * @param  int|null    $height           Destination height.
 *                                       If null, $height = $width.
 *                                       At least width or height must
 *                                       be provided.
 * @param  string      $mode             Cropping mode (MB_IMAGE_RESIZE_*).
 * @param  int         $outputQuality    Output quality (0-100).
 * @param  bool        $force            Force cache refreshing.
 * @return string|null                   Path of created image. Null if not
 *                                       successful.
 */
function get_resized_image_path(
    string $path,
    ?int   $width         = null,
    ?int   $height        = null,
    string $mode          = MB_IMAGE_RESIZE_COVER,
    int    $outputQuality = 70,
    bool   $force         = false,
): ?string
{
    if (!cfg('~@images.resizing.enabled')) return null;
    if (!is_file($path)) return null;
    if (!is_image_path($path)) return null;

    $cacheDir = cfg('~@images.resizing.dir');
    $cacheDir = $cacheDir ? get_path($cacheDir) : get_uploads_dir('images', 'resized');
    $cacheKey = hashed_sha1(implode(':', [ $path, $width, $height, $mode, $outputQuality ]));
    $cacheName = $cacheKey . rtrim('.' . get_file_extension($path, lower: true), '.');

    $cachePath = join_path(
        $cacheDir,
        substr($cacheName, 0, 2),
        substr($cacheName, 2, 2),
        $cacheName,
    );

    if (!$force
        && is_file($cachePath)
        && filemtime($cachePath) > filemtime($path)) return $cachePath;

    resize_image(
        src:           $path,
        dst:           $cachePath,
        width:         $width,
        height:        $height,
        mode:          $mode,
        outputQuality: $outputQuality,
    );

    return $cachePath;
}

/**
 * <USER>
 * Resize an image width <get_resized_image_path()> and returns
 * the URL of the created file.
 * @param  string      $path             Path of source image.
 * @param  int|null    $width            Destination width.
 *                                       If null, $width = $height.
 *                                       At least width or height must
 *                                       be provided.
 * @param  int|null    $height           Destination height.
 *                                       If null, $height = $width.
 *                                       At least width or height must
 *                                       be provided.
 * @param  string      $mode             Cropping mode (MB_IMAGE_RESIZE_*).
 * @param  int         $outputQuality    Output quality (0-100).
 * @param  bool        $force            Force cache refreshing.
 * @return string|null                   URL of created image. Null if not
 *                                       successful.
 */
function get_resized_image_url(
    string  $path,
    ?int    $width         = null,
    ?int    $height        = null,
    string  $mode          = MB_IMAGE_RESIZE_COVER,
    int     $outputQuality = 70,
    bool    $force         = false,
): ?string
{
    $path = get_resized_image_path(
        path:          $path,
        width:         $width,
        height:        $height,
        mode:          $mode,
        outputQuality: $outputQuality,
        force:         $force,
    );
    return $path ? path_to_url($path) : null;
}

/**
 * <USER>
 * Create a thumbnail of an image image and returns its path.
 * @param  string      $path Path of source image.
 * @param  int         $size Thumbnail width and height.
 * @return string|null       Path of created image. Null if not successful.
 */
function get_thumbnail_path(string $path, int $size = 256): ?string
{
    return get_resized_image_path(path: $path, width: $size);
}

/**
 * <USER>
 * Create a thumbnail of an image image and returns its URL.
 * @param  string      $path Path of source image.
 * @param  int         $size Thumbnail width and height.
 * @return string|null       URL of created image. Null if not successful.
 */
function get_thumbnail_url(string $path, int $size = 256): ?string
{
    return get_resized_image_url(path: $path, width: $size);
}

/**
 * <USER>
 * Generate a random image.
 * @param  int         $width     Width of image.
 * @param  null|int    $height    Height of image. Default is equals to width.
 * @param  null|string $path      Path to save file. If null, the image will be
 *                                rendered in the browser.
 * @param  int         $maxShapes Maximum number of shapes. More: the image
 *                                will be more complex. Less: the image will
 *                                be faster to render.
 * @param  string      $output    Output mode. 'auto' will switch between
 *                                direct rendering or saving to $path if
 *                                provided.
 *                                Possible values are:
 *                                  - auto
 *                                  - display: display in browser
 *                                  - write: write to $path
 *                                  - gd: returns GdImage instance
 *                                  - raw: returns image's raw code
 *                                  - base64: returns image base64-encoded
 */
function generate_random_image(
    int     $width           = 500,
    ?int    $height          = null,
    ?string $path            = null,
    int     $maxShapes       = 15,
    ?string $backgroundImage = null,
    string  $output          = 'auto',
): null | GdImage | string
{
    if ($output === 'auto') $output = $path === null ? 'display' : 'write';
    if ($height === null) $height = $width;

    $minAlpha = 0;
    $randomColorAlpha = function(?int $alpha = null) use ($minAlpha): array
    {
        if ($alpha === null) $alpha = mt_rand($minAlpha, 127);
        return [ mt_rand(0, 255), mt_rand(0, 255), mt_rand(0, 255), $alpha ];
    };

    $im = imagecreatetruecolor($width, $height);

    $bg = null;
    if ($backgroundImage) {
        list($bgWidth, $bgHeight) = getimagesize($backgroundImage);
        $bgIm = image_create($backgroundImage);
        imagecopyresampled($im, $bgIm, 0, 0, 0, 0, $width, $height, $bgWidth, $bgHeight);
        $minAlpha = 110;
        $bg = imagecolorallocatealpha($im, ...$randomColorAlpha(100));
    } else {
        $bg = imagecolorallocatealpha($im, ...$randomColorAlpha(0));
        imagefill($im, 0, 0, $bg);
    }

    $randomPolygon = function(int $maxPts = 20) use ($im, $width, $height, $randomColorAlpha): void
    {
        $color = imagecolorallocatealpha($im, ...$randomColorAlpha());
        $pts = [];
        for ($n = 0, $nbPts = mt_rand(3, $maxPts); $n < $nbPts; $n++) {
            $pts[] = mt_rand(0, $width);
            $pts[] = mt_rand(0, $height);
        }
        $num = count($pts) / 2;
        imagefilledpolygon($im, $pts, $num, $color);
    };

    $randomArc = function() use ($im, $width, $height, $randomColorAlpha): void
    {
        $arcStyles = [ IMG_ARC_PIE, IMG_ARC_CHORD, IMG_ARC_EDGED, IMG_ARC_NOFILL ];
        imagefilledarc($im,
            mt_rand(0, $width), mt_rand(0, $height),
            mt_rand(0, $width), mt_rand(0, $height),
            mt_rand(0, 360), mt_rand(0, 360),
            imagecolorallocatealpha($im, ...$randomColorAlpha()),
            $arcStyles[mt_rand(0, count($arcStyles) - 1)]);
    };

    $nbShapes = mt_rand(ceil($maxShapes / 2), $maxShapes);
    for ($i = 0; $i < $nbShapes; $i++) {
        if (mt_rand(0, 1) === 0) $randomArc();
        else $randomPolygon();
    }

    imagefilter($im, IMG_FILTER_SCATTER, 6, 10);

    if ($bg) {
        imagefilledpolygon($im, [ 0, 0, $width, 0, $width, $height * (mt_rand(3, 11) / 100), 0, $height * (mt_rand(3, 11) / 100) ], 4, $bg);
        imagefilledpolygon($im, [ 0, $height, $width, $height, $width, $height - ($height * (mt_rand(3, 11) / 100)), 0, $height - ($height * (mt_rand(3, 11) / 100)) ], 4, $bg);
        imagefilledpolygon($im, [ 0, 0, $width * (mt_rand(3, 11) / 100), 0, $width * (mt_rand(3, 11) / 100), $height, 0, $height ], 4, $bg);
        imagefilledpolygon($im, [ $width, 0, $width, $height, $width - ($width * (mt_rand(3, 11) / 100)), $height, $width - ($width * (mt_rand(3, 11) / 100)), 0 ], 4, $bg);
    }

    imagefilter($im, IMG_FILTER_BRIGHTNESS, -25);
    imagefilter($im, IMG_FILTER_CONTRAST, -25);

    if ($output === 'gd') return $im;

    if ($output === 'write') {
        imagewebp($im, $path);
        imagedestroy($im);
        return null;
    }

    if ($output === 'raw' || $output === 'base64') ob_start();
    else if ($output === 'display') header('Content-type: image/webp');

    imagewebp($im);
    imagedestroy($im);

    if ($output === 'display') return null;

    $raw = ob_get_clean();
    return $output === 'raw' ? $raw : base64_encode($raw);
}

// =============================================================================
// ---{ Listeners }-------------------------------------------------------------

listen('register_cfg_snippets', function(): array
{
    return [
        'images' => [
            'resizing' => [
                'enabled' => true,
                'dir'     => null,
            ],
        ],
    ];
});

// =============================================================================
