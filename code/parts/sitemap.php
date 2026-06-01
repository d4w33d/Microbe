<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Register Sitemap Source.
 * @param  string  $name     String to encode.
 * @param  Closure $func     Function which will be executed to generate the
 *                           sitemap. This function should returns an array of
 *                           associative arrays containing keys:
 *                               - loc (mandatory);
 *                               - lastmod;
 *                               - changefreq;
 *                               - priority.
 * @param  int     $priority Priority: lower is the value, higher is the
 *                           priority and sooner the links will appear
 *                           in the sitemap.
 */
function register_sitemap_source(
    string  $name,
    Closure $func,
    int     $priority = 50,
): void
{
    register_thing('sitemap_sources', (object) [
        'name'     => $name,
        'func'     => $func,
        'priority' => $priority,
    ]);
}

/**
 * <USER>
 * Returns registered Sitemap Sources.
 * @return array Sitemap Sources.
 */
function get_registered_sitemap_sources(): array
{
    $sources = get_registered_things('sitemap_sources');
    usort($sources, function(object $a, object $b): int
    {
        if ($a->priority < $b->priority) return -1;
        if ($a->priority > $b->priority) return 1;
        $aa = strtolower($a->name);
        $bb = strtolower($b->name);
        if ($aa < $bb) return -1;
        if ($aa > $bb) return 1;
        return 0;
    });
    return $sources;
}

/**
 * <USER>
 * Returns registered Sitemap Source.
 * @param  string  $sourceName Source name.
 * @return ?object             Sitemap Source.
 */
function get_registered_sitemap_source(string $sourceName): ?object
{
    foreach (get_registered_things('sitemap_sources') as $source) if ($source->name === $sourceName) return $source;
    return null;
}

/**
 * <USER>
 * Execute Sitemap Sources functions to fetch all links.
 * @param  string | null $sourceName Source name. If null, all sources are
 *                                   in use.
 * @return array                     Sitemap Links.
 */
function fetch_sitemap_links(?string $sourceName = null): array
{
    $links = [];
    foreach (get_registered_sitemap_sources() as $src) {
        if ($sourceName !== null && $sourceName !== $src->name) continue;
        $links = array_merge($links, array_values(array_filter(array_map(function(array $link): ?array
        {
            if (!$link['loc']) return null;
            $link['loc'] = str_contains($link['loc'], '://') ? $link['loc'] : url($link['loc'], host: true);
            if (!array_key_exists('lastmod', $link) || !$link['lastmod']) $link['lastmod'] = new DateTime();
            if (is_string($link['lastmod'])) $link['lastmod'] = new DateTime($link['lastmod']);
            return $link;
        }, call_user_func($src->func)))));
    }
    return $links;
}

/**
 * <USER>
 * Generate Sitemap based on registered Sitemap Sources.
 * @param  string        $path XML Sitemap output path.
 * @return string | null       XML string if path is false. Else, null.
 */
function generate_sitemap(string | null | false $path = null, ?string $sourceName = null): ?string
{
    $source = null;
    if ($sourceName && !($source = get_registered_sitemap_source($sourceName))) throw new Microbe_Exception("Invalid Sitemap Source Name: {$sourceName}");
    if ($path === null) $path = $source ? 'sitemap-' . preg_replace('/[^\p{L}\p{N}]+/u', '_', $source->name) . '.xml' : 'sitemap.xml';
    if ($path) $path = get_path($path);

    $dom = new DOMDocument('1.0', 'UTF-8');
    $dom->formatOutput = true;

    $urlset = $dom->createElement('urlset');
    $urlset->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
    $urlset->setAttribute('xmlns', 'http://www.sitemaps.org/schemas/sitemap/0.9');
    $urlset->setAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');
    $urlset->setAttribute('xmlns:image', 'http://www.google.com/schemas/sitemap-image/1.1');
    $urlset->setAttribute('xsi:schemaLocation', 'http://www.sitemaps.org/schemas/sitemap/0.9 http://www.sitemaps.org/schemas/sitemap/0.9/sitemap.xsd http://www.google.com/schemas/sitemap-image/1.1 http://www.google.com/schemas/sitemap-image/1.1/sitemap-image.xsd');
    $dom->appendChild($urlset);

    foreach (fetch_sitemap_links($sourceName) as $link) {
        $link = (object) array_merge([
            'loc'        => null,
            'lastmod'    => null,
            'changefreq' => null,
            'priority'   => null,
        ], $link);
        if (!$link->loc) continue;
        $url = $dom->createElement('url');
        $url->appendChild($dom->createElement('loc', $link->loc));
        $url->appendChild($dom->createElement('lastmod', $link->lastmod->format('Y-m-d\TH:i:sP')));
        if ($link->changefreq) $url->appendChild($dom->createElement('changefreq', $link->changefreq));
        if ($link->priority) $url->appendChild($dom->createElement('priority', $link->priority));
        $urlset->appendChild($url);
    }

    if (!$path) return $dom->saveXML();
    $dom->save($path);
    return null;
}

/**
 * <USER>
 * Check all existing sitemaps.
 * @param  bool   $stats  Include number of links.
 * @param  string $format Regular expression used to match expected files.
 * @param  string $dir    Directory (based on project root) where the files
 *                        are expected.
 * @return array          An array of sitemaps Microbe_File entities.
 *                        If $stats is true, objects with corresponding
 *                        Microbe_File instance and number of links will be
 *                        returned.
 */
function get_existing_sitemaps(
    bool   $stats  = true,
    string $format = '/^sitemap(-(?<n>.+))?\.xml$/i',
    string $dir    = '/',
): array
{
    $dir = ($dir = trim($dir, '/')) ? get_path($dir) : get_root_dir();
    $sitemaps = [];
    foreach (get_files($dir) as $f) {
        if (!preg_match($format, $f->getName(), $m)) continue;
        if (!$stats) {
            $sitemaps[] = $f;
            continue;
        }
        $doc = new DOMDocument();
        $doc->load($f->getPath());
        $sitemaps[] = (object) [ 'file' => $f, 'links' => count($doc->getElementsByTagName('url')) ];
        unset($doc);
    }
    usort($sitemaps, function(Microbe_File | stdClass $a, Microbe_File | stdClass $b): int
    {
        $aa = strtolower($a instanceof Microbe_File ? $a->getName() : $a->file->getName());
        $bb = strtolower($b instanceof Microbe_File ? $b->getName() : $b->file->getName());
        if (!str_contains($aa, '-') && str_contains($bb, '-')) return -1;
        if (str_contains($aa, '-') && !str_contains($bb, '-')) return 1;
        if ($aa < $bb) return -1;
        if ($aa > $bb) return 1;
        return 0;
    });
    return $sitemaps;
}

// =============================================================================
// ---{ Listeners }-------------------------------------------------------------

listen('init', function(): void
{
    register_task(
        bundle: 'core',
        name:   'sitemap_generate',
        args:   [ 'src' => [ 'desc' => "Source Name", 'optional' => true ] ],
        func:   function(string $ctx, object $args): void
        {
            generate_sitemap(sourceName: $args->src ?? null);
            json_success();
        },
    );
});

// =============================================================================
