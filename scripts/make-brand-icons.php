<?php

/**
 * Every icon of the app, drawn from the owner's logo (scripts/brand/logo-source.svg; owner, 2026-10-09: ".ico banao jo
 * industrial standard ha ... meri app mein lagao ... aur android app per bhi dal do"). Run once after the logo changes,
 * and commit what it writes into public/:
 *
 *   php scripts/make-brand-icons.php                    the web app's icons, in public/
 *   php scripts/make-brand-icons.php --android=<res>    and the Android player app's, into its app/src/main/res
 *   php scripts/make-brand-icons.php --preview          a sheet of the small sizes to judge, in storage/app/private
 *
 * Headless Chrome draws the logo at each size it is used at, so a 16 px favicon is the vector drawn at 16 px, never a
 * large picture shrunk. Below 64 px the logo's fine detail (its pixels, sparkles and glows) is noise, so those sizes
 * draw a simpler copy: the monitor and the arrow alone.
 */
$root = dirname(__DIR__);
$options = getopt('', ['android:', 'preview', 'chrome:']);
$chrome = $options['chrome'] ?? 'C:/Program Files/Google/Chrome/Application/chrome.exe';
$work = $root.'/storage/app/private/brand-icons';

// The drawing's own extent in the logo's coordinates: the monitor's body, the arrow's tip and the stand's foot.
const BOX = ['x' => 574.0, 'y' => 105.94, 'w' => 887.06, 'h' => 700.06];
const WHITE = '#ffffff';

if (! is_file($chrome)) {
    fwrite(STDERR, "Chrome not found at {$chrome}: pass --chrome=<path to chrome>\n");
    exit(1);
}
@mkdir($work, 0777, true);

$full = cleaned(file_get_contents($root.'/scripts/brand/logo-source.svg'));
$simple = simplified($full);
$tight = sideWithMargin(0.02);

/** The logo without the C2PA manifest and its padding (19 KB of the 21), and without the space between its tags. */
function cleaned(string $svg): string
{
    $svg = preg_replace('~<metadata>.*?</metadata>~s', '', $svg);
    $svg = preg_replace('~\s+xmlns:c2pa="[^"]*"~', '', $svg);

    return trim(preg_replace('~>\s+<~', '><', $svg))."\n";
}

/** For the small sizes: the monitor and the arrow alone. */
function simplified(string $svg): string
{
    $dom = new DOMDocument;
    $dom->loadXML($svg);
    $xpath = new DOMXPath($dom);
    $xpath->registerNamespace('s', 'http://www.w3.org/2000/svg');
    $drop = [
        '//s:g[@id="Pixels"]', '//s:g[@id="Arrow-Sparkles"]', '//s:*[@id="Trail"]', '//s:*[@id="Trail-Glow"]',
        '//s:*[@id="Trail-Dot"]', '//s:g[@id="Arrow"]/s:circle', '//s:*[@id="Screen-Glow"]', '//s:*[@id="Screen-Bottom-Line"]',
    ];
    foreach ($drop as $query) {
        foreach (iterator_to_array($xpath->query($query)) as $node) {
            $node->parentNode->removeChild($node);
        }
    }

    // Gradients nothing names any more go too.
    foreach (iterator_to_array($xpath->query('//s:defs/*')) as $gradient) {
        if (! str_contains($dom->saveXML($dom->documentElement), 'url(#'.$gradient->getAttribute('id').')')) {
            $gradient->parentNode->removeChild($gradient);
        }
    }

    return $dom->saveXML($dom->documentElement)."\n";
}

/** The logo framed as a square of the given side, in its own units, centred on its drawing. */
function framed(string $svg, float $side): string
{
    $cx = BOX['x'] + BOX['w'] / 2;
    $cy = BOX['y'] + BOX['h'] / 2;
    $viewBox = sprintf('%.1f %.1f %.1f %.1f', $cx - $side / 2, $cy - $side / 2, $side, $side);

    return preg_replace('~viewBox="[^"]*" width="[^"]*" height="[^"]*"~', 'viewBox="'.$viewBox.'"', $svg, 1);
}

/** A side that leaves `margin` of it free on each edge of the drawing's wider dimension. */
function sideWithMargin(float $margin): float
{
    return BOX['w'] / (1 - 2 * $margin);
}

/** A side whose central circle of `fraction` of it holds the whole drawing: a maskable or an adaptive icon's safe zone. */
function sideForSafeCircle(float $fraction): float
{
    return sqrt((BOX['w'] / 2) ** 2 + (BOX['h'] / 2) ** 2) / ($fraction / 2);
}

/**
 * Draws a page of width × height CSS pixels with headless Chrome, `scale` device pixels to each, transparent where the
 * page is, and stores it compressed.
 */
function render(string $html, int $width, int $height, string $png, float $scale = 1): void
{
    global $chrome, $work;

    $page = $work.'/page.html';
    file_put_contents($page, $html);
    $command = sprintf(
        // The virtual time lets a web font (the banner's name) arrive before the picture is taken.
        '"%s" --headless=new --disable-gpu --hide-scrollbars --force-device-scale-factor=%s --default-background-color=00000000 --virtual-time-budget=10000 --window-size=%d,%d --screenshot="%s" "file:///%s" 2>&1',
        $chrome, $scale, $width, $height, str_replace('/', DIRECTORY_SEPARATOR, $png), str_replace('\\', '/', realpath($page)),
    );
    @unlink($png);
    exec($command, $output, $code);
    if ($code !== 0 || ! is_file($png)) {
        fwrite(STDERR, "Chrome could not draw {$png}:\n".implode("\n", $output)."\n");
        exit(1);
    }

    // Chrome writes its PNGs fast, not small: the same pixels again at full compression.
    $image = imagecreatefrompng($png);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    imagepng($image, $png, 9, PNG_ALL_FILTERS);
    imagedestroy($image);
}

/** The SVG at size × size, on a tile of a colour (with rounded corners, as a fraction of the side) or on nothing. */
function icon(string $svg, int $size, string $png, ?string $tile = null, float $radius = 0): void
{
    @mkdir(dirname($png), 0777, true);
    $background = $tile ? "background:{$tile};" : '';
    $corners = $radius > 0 ? 'border-radius:'.round($radius * $size).'px;' : '';
    $data = 'data:image/svg+xml;base64,'.base64_encode($svg);
    render("<!doctype html><html><head><style>html,body{margin:0;padding:0;background:transparent}div{width:{$size}px;height:{$size}px;overflow:hidden;{$background}{$corners}}img{display:block;width:{$size}px;height:{$size}px}</style></head><body><div><img src=\"{$data}\"></div></body></html>", $size, $size, $png);
}

/**
 * Android 13's themed icon: one colour, which the launcher tints, read from the picture's alpha. The logo's bright parts
 * (its bezel and its arrow) stay and its dark ones (the screen, the body) go, so the outline of a screen with the arrow
 * across it is what is left.
 */
function monochrome(string $png): void
{
    $image = imagecreatefrompng($png);
    imagealphablending($image, false);
    imagesavealpha($image, true);
    for ($y = 0, $h = imagesy($image); $y < $h; $y++) {
        for ($x = 0, $w = imagesx($image); $x < $w; $x++) {
            $c = imagecolorat($image, $x, $y);
            $alpha = 1 - (($c >> 24) & 0x7F) / 127;
            $light = (0.2126 * (($c >> 16) & 0xFF) + 0.7152 * (($c >> 8) & 0xFF) + 0.0722 * ($c & 0xFF)) / 255;
            $keep = max(0, min(1, ($light - 0.20) / 0.06)) * $alpha;
            imagesetpixel($image, $x, $y, imagecolorallocatealpha($image, 255, 255, 255, (int) round(127 * (1 - $keep))));
        }
    }
    imagepng($image, $png, 9, PNG_ALL_FILTERS);
    imagedestroy($image);
}

/** favicon.ico of PNG pictures, which every browser and Windows since Vista reads. */
function ico(array $pngs, string $file): void
{
    $directory = pack('vvv', 0, 1, count($pngs));
    $pictures = '';
    $offset = 6 + 16 * count($pngs);
    foreach ($pngs as $size => $png) {
        $bytes = file_get_contents($png);
        $directory .= pack('CCCCvvVV', $size, $size, 0, 0, 1, 32, strlen($bytes), $offset);
        $pictures .= $bytes;
        $offset += strlen($bytes);
    }
    file_put_contents($file, $directory.$pictures);
}

if (isset($options['preview'])) {
    $cells = '';
    foreach ([16, 32, 48, 64, 180] as $size) {
        foreach (['full' => $full, 'simple' => $simple] as $name => $svg) {
            $data = 'data:image/svg+xml;base64,'.base64_encode(framed($svg, $tight));
            $cells .= "<figure><div class='w'><img src='{$data}' width='{$size}' height='{$size}'></div><div class='d'><img src='{$data}' width='{$size}' height='{$size}'></div><figcaption>{$name} {$size}</figcaption></figure>";
        }
    }
    render("<!doctype html><html><head><style>body{margin:0;padding:16px;font:12px sans-serif;background:#e5e7eb;display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end}figure{margin:0;text-align:center}.w,.d{padding:8px;display:inline-block}.w{background:#fff}.d{background:#111827}</style></head><body>{$cells}</body></html>", 1500, 600, $work.'/preview.png');
    echo "preview: {$work}/preview.png\n";
    exit;
}

// ── The web app (public/) ──────────────────────────────────────────────────────────────────────────────────────────
// The tab's icons: an SVG for every browser that takes one, and favicon.ico of 16, 32 and 48 px for the rest.
$public = $root.'/public';
file_put_contents($public.'/icon.svg', framed($simple, $tight));
$small = [];
foreach ([16, 32, 48] as $size) {
    icon(framed($simple, $tight), $size, $small[$size] = "{$work}/favicon-{$size}.png");
}
ico($small, $public.'/favicon.ico');

// The brand mark the panel draws beside its name.
@mkdir($public.'/brand', 0777, true);
file_put_contents($public.'/brand/logo.svg', framed($full, sideWithMargin(0.01)));

// iOS fills a transparent icon with black and rounds the corners itself: a white square, room around the drawing.
icon(framed($full, sideWithMargin(0.1)), 180, $public.'/apple-touch-icon.png', WHITE);
// The manifests' icons (the panel's and the player's): as they are, and one whose drawing stays inside the central 80%
// circle on a solid tile, which every mask a launcher cuts keeps whole.
icon(framed($full, $tight), 192, $public.'/icon-192.png');
icon(framed($full, $tight), 512, $public.'/icon-512.png');
icon(framed($full, sideForSafeCircle(0.8)), 512, $public.'/icon-maskable-512.png', WHITE);
echo "web icons written to public/\n";

// ── The Android player app ─────────────────────────────────────────────────────────────────────────────────────────
if (isset($options['android'])) {
    $res = rtrim($options['android'], '/\\');
    foreach (['mdpi' => 1, 'hdpi' => 1.5, 'xhdpi' => 2, 'xxhdpi' => 3, 'xxxhdpi' => 4] as $density => $scale) {
        // Adaptive icon (Android 8 on): a 108 dp foreground whose drawing stays inside the 66 dp circle every mask keeps,
        // and its one-colour copy for Android 13's themed icons.
        $adaptive = (int) round(108 * $scale);
        icon(framed($full, sideForSafeCircle(66 / 108)), $adaptive, "{$res}/mipmap-{$density}/ic_launcher_foreground.png");
        icon(framed($simple, sideForSafeCircle(66 / 108)), $adaptive, $themed = "{$res}/mipmap-{$density}/ic_launcher_monochrome.png");
        monochrome($themed);
        // Before Android 8, and launchers that take no adaptive icon: 48 dp, square and round, on white.
        $size = (int) round(48 * $scale);
        $svg = $size <= 72 ? $simple : $full;
        icon(framed($svg, sideWithMargin(0.1)), $size, "{$res}/mipmap-{$density}/ic_launcher.png", WHITE, 0.18);
        icon(framed($svg, sideForSafeCircle(0.88)), $size, "{$res}/mipmap-{$density}/ic_launcher_round.png", WHITE, 0.5);
    }

    // Android TV's banner, 160 × 90 dp (320 × 180 at xhdpi, a 1080p TV's): the logo and the name, readable across a
    // room, at every density, so a 4K launcher draws it sharp.
    $logo = 'data:image/svg+xml;base64,'.base64_encode(framed($full, $tight));
    $banner = <<<HTML
        <!doctype html><html><head>
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@700&display=block" rel="stylesheet">
        <style>
        html,body{margin:0;padding:0}
        .b{width:320px;height:180px;box-sizing:border-box;padding:0 22px;display:flex;align-items:center;gap:14px;
          background:linear-gradient(135deg,#ffffff 0%,#eff6ff 100%);font-family:Outfit,sans-serif}
        img{width:112px;height:112px;flex:none}
        p{margin:0;color:#101828;font-weight:700;font-size:25px;line-height:1.05;letter-spacing:-.01em}
        </style></head><body><div class="b"><img src="{$logo}"><p>The Display<br>Solution</p></div></body></html>
        HTML;
    foreach (['mdpi' => 0.5, 'hdpi' => 0.75, 'xhdpi' => 1, 'xxhdpi' => 1.5, 'xxxhdpi' => 2] as $density => $scale) {
        @mkdir("{$res}/drawable-{$density}", 0777, true);
        render($banner, 320, 180, "{$res}/drawable-{$density}/banner.png", $scale);
    }
    echo "Android icons written to {$res}\n";
}

array_map('unlink', glob($work.'/*.{html,png}', GLOB_BRACE) ?: []);
