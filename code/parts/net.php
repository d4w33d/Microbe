<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Returns given URL with a random parameter.
 * @param  string $url   URL to process.
 * @param  string $param Random parameter name.
 * @return string        Uncached URL.
 */
function url_uncached(string $url, string $param = '_'): string
{
    return $url . (str_contains($url, '?') ? '&' : '?') . http_build_query([ $param => uid(9) ]);
}

/**
 * <USER>
 * Check if a URL seems valid.
 * @param  mixed $url  Something which should be a URL.
 * @param  bool  $flex Allows URLs without hosts.
 * @return bool        Is valid URL or not?
 */
function is_valid_url(mixed $url, bool $flex = false): bool
{
    if (!is_string($url)) return false;
    if ($flex) return (bool) preg_match('~^(?:[a-z][a-z0-9+.-]*://[^\s/][^\s]*|//[^\s/][^\s]*|/[^?\s#][^\s]*)$~i', $url);
    return (bool) preg_match('/[^A-Za-z0-9._~:\/?#\[\]@!$&\'()*+,;%=]/', $url);
}

/**
 * <USER>
 * Try to get all URLs in a specified string.
 * @param  string      $str          String to process..
 * @param  bool        $validatePath Check if a local path can be matched.
 * @param  string|null $regex        Validate the URL with a custom regex.
 * @return array                     Array containing the URLs or objects
 *                                   with the URLs and paths.
 */
function fetch_urls_in_string(
    string        $str,
    bool          $validatePath = false,
    string | bool $validateHost = true,
    ?string       $regex = null,
): array
{
    $urls = [];
    foreach ([
        '#\b(https?|s?ftp|ssh)://[^,\s()<>]+(?:\([\w\d]+\)|([^,[:punct:]\s]|/))#',
        '#"/[^"]+"#',
        '#\\"/[^"]+\\"#',
        '#\'/[^\']+\'#',
        '#\\\'/[^\']+\\\'#',
    ] as $re) {
        if (preg_match_all($re, $str, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $url = unesc(trim($m[0], '"\'\\'));
                if (!is_valid_url($url, flex: true)) continue;
                $cleanUrl = preg_replace('/[?&\#].*$/', '', $url);
                if ($regex && !preg_match($regex, $cleanUrl)) continue;
                if (!$validatePath) {
                    $urls[] = $url;
                    continue;
                }
                if (!($path = url_to_path($cleanUrl, validateHost: $validateHost)) || !is_file($path)) continue;
                $urls[] = (object) [ 'path' => $path, 'url' => $cleanUrl, 'original' => $url ];
            }
        }
    }
    return $urls;
}

/**
 * <USER>
 * @param  string $str URL or domain name.
 * @return string      Domain name, or null if $str doesn't contains one.
 */
function extract_domain_name(string $str): ?string
{
    return parse_url($str, PHP_URL_HOST) ?: null;
}

/**
 * <USER>
 * @param  string $str Probable domain name.
 * @return string      Top level domain name, or null if $str
 *                     is not a domain name.
 */
function extract_top_level_domain_name(string $str): ?string
{
    return preg_replace('/^(.*\.)*([^.]*\.[a-z]+)$/', '$2', $str) ?: null;
}

/**
 * <USER>
 * Check if a string is a valid domain name, including subdomains.
 * @param  string  $domain Probable domain name.
 * @return boolean         Is it a valid domain name string or not?
 */
function is_valid_domain_name(string $domain): bool
{
    return (bool) preg_match('/^(?!\-)(?:(?:[a-zA-Z\d][a-zA-Z\d\-]{0,61})?[a-zA-Z\d]\.){1,126}(?!\d+)[a-zA-Z\d]{1,63}$/', $domain);
}

/**
 * <USER>
 * Check if a string is a valid host name: domain, subdomain, IP, IPv6, etc.
 * @param  string  $host Probable hostname.
 * @return boolean       Is it a valid hostname string or not?
 */
function is_valid_host_name(string $host): bool
{
    $clean = trim($host, '[]');
    if (filter_var($clean, FILTER_VALIDATE_IP)) return true;
    $pattern = '/^(?:[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?\.){0,126}[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?$/';
    return strlen($host) <= 253 && preg_match($pattern, $host) === 1;
}

/**
 * <USER>
 * Tries to retrieve a file name from a given URL.
 * @param  string      $url URL.
 * @return string|null      File name if found.
 */
function get_url_file_name(string $url): ?string
{
    if (!($path = parse_url($url, PHP_URL_PATH))) return null;
    if (!str_contains($path, '/')) return $path;
    if ($name = basename($path)) return $name;
    return null;
}

/**
 * <USER>
 * Execute a cURL request.
 * @param  string           $url               Url to fetch.
 * @param  string           $method            HTTP method (GET, POST, DELETE,
 *                                             etc.)
 * @param  string|array     $data              Query strings to pass in URL if
 *                                             $method is GET, or postfields
 *                                             added to the request.
 * @param  bool             $json              Parse result as JSON data.
 * @param  array            $headers           Array of headers to pass to
 *                                             request.
 * @param  array            $cookies           Array of cookies to pass to
 *                                             request.
 * @param  int              $maxRedirs         Maximum number of redirects.
 * @param  bool             $verifyCertificate Verify peer certificate or not.
 * @param  string|null|bool $userAgent         User agent. If true, a standard
 *                                             user agent will be set.
 * @return mixed                               Response, or null if error.
 */
function curl(
    string                $url,
    string                $method            = 'get',
    string | array | null $data              = null,
    bool                  $json              = false,
    array                 $headers           = [],
    array                 $cookies           = [],
    int                   $maxRedirs         = 3,
    bool                  $verifyCertificate = true,
    string | null | bool  $userAgent         = null,
    ?string               $username          = null,
    ?string               $password          = null,
): mixed
{
    $method = strtolower($method);
    $ch = curl_init();

    $opts = [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
    ];

    if ($userAgent === true) $userAgent = 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/109.0 Safari/537.36';
    if ($userAgent) $opts[CURLOPT_USERAGENT] = $userAgent;

    if ($verifyCertificate) {
        $opts[CURLOPT_SSL_VERIFYHOST] = false;
        $opts[CURLOPT_SSL_VERIFYPEER] = false;
    }

    if ($maxRedirs > 0) {
        $opts[CURLOPT_FOLLOWLOCATION] = true;
        $opts[CURLOPT_MAXREDIRS] = $maxRedirs;
    }

    if ($username) $opts[CURLOPT_USERPWD] = $username . ':' . ($password ?: '');
    if ($headers) $opts[CURLOPT_HTTPHEADER] = $headers;

    if ($cookies) {
        $inlineCookies = [];
        foreach ($cookies as $k => $v) $inlineCookies[] = urlencode($k) . '=' . urlencode($v);
        $opts[CURLOPT_COOKIE] = implode(';', $inlineCookies);
    }

    if ($data && is_array($data)) $data = http_build_query($data);

    if ($method === 'get') {
        if ($data) $opts[CURLOPT_URL] .= (str_contains($opts[CURLOPT_URL], '?') ? '&' : '?') . $data;
    } else if ($method === 'post') {
        if ($data) $opts[CURLOPT_POSTFIELDS] = $data;
        $opts[CURLOPT_POST] = true;
    } else {
        if ($data) $opts[CURLOPT_POSTFIELDS] = $data;
        $opts[CURLOPT_CUSTOMREQUEST] = strtoupper($method);
    }

    curl_setopt_array($ch, $opts);
    $raw = curl_exec($ch) ?: null;
    cfg('@core.curl.last.error', curl_error($ch) ?: null);
    cfg('@core.curl.last.info', curl_getinfo($ch) ?: null);
    cfg('@core.curl.last.response', $raw);
    if ($json) return $raw ? json_decode($raw) : null;
    return $raw;
}

/**
 * <USER>
 * Perform a cURL GET request.
 * @param  string           $url               Url to fetch.
 * @param  string|array     $fields            Postfields added to the request.
 * @param  bool             $json              Parse result as JSON data.
 * @param  bool             $verifyCertificate Verify peer certificate or not.
 * @param  string|null      $storePath         Path where to store the result.
 * @param  array            $headers           Array of headers to pass to
 *                                             request.
 * @param  string|null|bool $userAgent         User agent. If true, a standard
 *                                             user agent will be set.
 * @return mixed                               Response, or null if error.
 */
function curl_get(
    string               $url,
    array                $query             = [],
    bool                 $json              = false,
    bool                 $verifyCertificate = true,
    ?string              $storePath         = null,
    array                $headers           = [],
    bool                 $cached            = false,
    ?DateInterval        $cacheTtl          = null,
    string | null | bool $userAgent         = null,
): mixed
{
    $result = null;
    $cachedInstance = null;

    if ($cached) {
        $cacheKey = $url;
        if ($headers) {
            $cachedHeaders = $headers;
            ksort($cachedHeaders);
            $cacheKey .= ':H:' . sha1(json_encode($cachedQuery));
        }
        if ($query) {
            $cachedQuery = $query;
            ksort($cachedQuery);
            $cacheKey .= ':Q:' . sha1(json_encode($cachedQuery));
        }
        $cachedInstance = cached(
            key:  sha1($cacheKey),
            ttl:  $cacheTtl,
            json: true,
        );
        $result = $cachedInstance->get();
    }


    if (!$result) {
        $result = curl(
            url:               $url,
            method:            'get',
            data:              $query,
            json:              $json,
            verifyCertificate: $verifyCertificate,
            headers:           $headers,
            userAgent:         $userAgent,
        );

        if ($cachedInstance) $cachedInstance->set($result);
    }

    if ($storePath) {
        if (!is_string($result)) return $result;
        rmkdir(dirname($storePath));
        file_put_contents($storePath, $result);
    }

    return $result;
}

/**
 * <USER>
 * Perform a cURL POST request.
 * @param  string       $url               Url to fetch.
 * @param  string|array $fields            Postfields added to the request.
 * @param  bool         $json              Parse result as JSON data.
 * @param  bool         $verifyCertificate Verify peer certificate or not.
 * @return mixed                           Response, or null if error.
 */
function curl_post(string $url, string | array $fields = [], bool $json = false, bool $verifyCertificate = true): mixed
{
    return curl(url: $url, method: 'post', data: $fields, json: $json, verifyCertificate: $verifyCertificate);
}

/**
 * <USER>
 * Returns the last cURL error (when used <curl_get> or <curl_post>).
 * @return string|null The last error, or null if no error.
 */
function curl_last_error(): ?string
{
    return cfg('~@core.curl.last.error') ?: null;
}

/**
 * <USER>
 * Returns the last cURL info (when used <curl_get> or <curl_post>).
 * @param  string|null $key The key of the info to get.
 * @return mixed            The last error, or null if no error.
 */
function curl_last_info(?string $key = null): mixed
{
    $info = cfg('~@core.curl.last.info') ?: null;
    if ($key === null) return $info;
    return $info[$key] ?? null;
}

/**
 * <USER>
 * Returns the last cURL response (when used <curl_get> or <curl_post>).
 * @return string|null The last response, or null if nothing.
 */
function curl_last_response(): ?string
{
    return cfg('~@core.curl.last.response') ?: null;
}

// =============================================================================
