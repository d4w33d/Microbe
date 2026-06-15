<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Get/Set a cookie.
 * @param  string      $name     Name of the cookie.
 * @param  mixed       $value    Value to set. If null, the current value of
 *                               the cookie will be returned.
 * @param  int|null    $lifetime TTL of the cookie in seconds.
 * @param  string|null $samesite Samesite property for the cookie:
 *                               'None', 'Lax' or 'Strict'.
 * @param  bool|null   $secure   Ask to the browser to send the cookie only
 *                               through HTTPS.
 * @param  bool        $httponly If true, will disallow JavaScript to read
 *                               the cookie.
 * @param  string|null $domain   Domain name. If null, it will be set to the
 *                               top-level domain name.
 * @return mixed                 The value of the cookie.
 */
function cookie(
    string  $name,
    mixed   $value    = null,
    ?int    $lifetime = null,
    ?string $samesite = null,
    ?bool   $secure   = null,
    bool    $httponly = false,
    ?string $domain   = null,
): mixed
{
    if ($value === null) {
        return array_key_exists($name, $_COOKIE) ? $_COOKIE[$name] : null;
    }

    if ($value === false) {
        $lifetime = -1 * 24 * 60 * 60;
    }

    if ($secure === null) {
        $secure = is_ssl();
    }

    setcookie($name, $value ?: '', [
        'expires'  => $lifetime === null ? 0 : (time() + $lifetime),
        'path'     => '/',
        'domain'   => $domain ?: get_top_level_domain_name(),
        'secure'   => $secure,
        'httponly' => $httponly,
        'samesite' => $samesite === null ? ($secure ? 'None' : 'Lax') : $samesite,
    ]);

    if ($value === false) {
        if (array_key_exists($name, $_COOKIE)) unset($_COOKIE[$name]);
    } else {
        $_COOKIE[$name] = $value;
    }

    return $value;
}

/**
 * <USER>
 * Define a cookie configuration for further usage with <defined_cookie>
 * and Microbe_Cookie instance.
 * @param  string       $name       Name of the cookie configuration.
 * @param  string|null  $cookieName Name of the cookie.
 *                                  If null, $name will be used.
 *                                  In case of multiple cookie, the cookie
 *                                  index is defined in the name using the
 *                                  substring Microbe_Cookie::IDX_SUBSTRING.
 * @param  int|string   $ttl        Cookie's time-to-live in seconds or a
 *                                  duration string.
 * @param  bool         $multiple   Multiple cookie or not?
 * @param  string       $domain     Domain configuration: 'current' or 'top'.
 * @param  Closure|null $modifier   Modifier callback function, which will be
 *                                  applied on cookie's values.
 */
function define_cookie(
    string       $name,
    ?string      $cookieName = null,
    int | string $ttl        = 0,
    bool         $multiple   = false,
    string       $domain     = 'current',
    ?Closure     $modifier   = null,
): void
{
    if ($cookieName === null) $cookieName = $name;
    $cookies = get_defined_cookies();
    $cookies[$name] = new Microbe_Cookie(
        name:     $cookieName,
        ttl:      $ttl,
        multiple: $multiple,
        domain:   $domain,
        modifier: $modifier,
    );
    set_defined_cookies($cookies);
}

/**
 * <USER>
 * Get a defined cookie's Microbe_Cookie instance.
 * @param  string              $name Name of the cookie configuration.
 * @return Microbe_Cookie|null Instance, or null if $name is not defined.
 */
function defined_cookie(string $name): ?Microbe_Cookie
{
    return cfg('~@core.cookies.defined')[$name] ?? null;
}

/**
 * Get the defined cookies.
 * @return array Array of defined cookies
 */
function get_defined_cookies(): array
{
    return cfg('~@core.cookies.defined') ?: [];
}

/**
 * Set the defined cookies.
 * @param array $cookies Key-value array containing Microbe_Cookie instances.
 */
function set_defined_cookies(array $cookies): void
{
    cfg('~@core.cookies.defined', $cookies);
}

class Microbe_Cookie
{

    public const IDX_SUBSTRING = '{idx}';
    public const IDX_LENGTH = 3;

    private ?string $name = null;
    private ?int $ttl = null;
    private ?bool $multiple = null;
    private ?string $domain = null;
    private ?bool $sameSite = null;
    private ?bool $secure = null;
    private ?bool $httpOnly = false;
    private ?Closure $modifier = null;

    public function __construct(
        string       $name,
        int | string $ttl,
        bool         $multiple,
        string       $domain,
        ?bool        $sameSite = null,
        ?bool        $secure   = null,
        bool         $httpOnly = false,
        ?Closure     $modifier = null,
    )
    {
        $this->name = $name;
        $this->ttl = parse_duration($ttl);
        $this->multiple = $multiple;
        $this->sameSite = $sameSite;
        $this->secure = $secure;
        $this->httpOnly = $httpOnly;
        $this->modifier = $modifier;

             if ($domain === 'current') $this->domain = get_domain_name();
        else if ($domain === 'top') $this->domain = get_top_level_domain_name();
        else throw new Microbe_Exception("Invalid domain name when defining cookie");
    }

    public function getIndexedName(string | int $idx): string
    {
        return str_replace(static::IDX_SUBSTRING, str_pad($idx, static::IDX_LENGTH, '0', STR_PAD_LEFT), $this->name);
    }

    public function all(bool $modify = true): array
    {
        $regex = '/^' . str_replace($s = ',__idx__,', '(?<n>[0-9]+)', preg_quote($this->getIndexedName($s), '/')) . '$/';
        $cookies = [];
        foreach ($_COOKIE as $k => $v) {
            if (!preg_match($regex, $k, $m)) continue;
            $cookie = (object) [
                'name'  => $k,
                'idx'   => (int) $m['n'],
                'value' => $v,
            ];
            if ($modify && $this->modifier) if (($cookie = call_user_func($this->modifier, $cookie)) === false) continue;
            $cookies[] = $cookie;
        }
        usort($cookies, fn(object $a, object $b): int => ($a->idx < $b->idx ? -1 : ($a->idx > $b->idx ? 1 : 0)));
        return array_values($cookies);
    }

    public function get(?Closure $filter = null, bool $single = false, bool $modify = true): mixed
    {
        if (!$this->multiple) {
            $v = $_COOKIE[$this->name] ?? null;
            if (!$modify || !$this->modifier) return $v;
            return ($v = call_user_func($this->modifier, $v)) === false ? null : $v;
        }

        $cookies = $this->all();
        if (!$filter) return $cookies;
        if (!($cookies = array_values(array_filter($cookies, $filter)))) return $single ? null : [];
        return $single ? $cookies[0] : $cookies;
    }

    public function set(string | int | null $valueOrIdx = null, mixed $value = null): static
    {
        if ($value === null) { $value = $valueOrIdx; $valueOrIdx = null; }
        if (is_string($valueOrIdx)) $valueOrIdx = (int) $valueOrIdx;

        $idx = $valueOrIdx;
        $name = $this->name;
        if ($this->multiple) $name = $this->getIndexedName($idx);
        else if ($idx !== null) throw new Microbe_Exception("Trying to set an indexed cookie on a non-multiple cookie");

        cookie(
            name:     $name,
            value:    $value,
            lifetime: $this->ttl,
            domain:   $this->domain,
            samesite: $this->sameSite,
            secure:   $this->secure,
            httponly: $this->httpOnly,
        );
        return $this;
    }

    public function add(mixed $value = null): static
    {
        if (!$this->multiple) throw new Microbe_Exception("Trying to add an entry on a non-multiple cookie");
        $this->set(valueOrIdx: $this->nextIdx(), value: $value);
        return $this;
    }

    public function delete(int | Closure | null $cond = null): static
    {
        if (!$this->multiple) {
            cookie($this->name, false);
            return $this;
        }
        foreach ($this->all() as $cookie) {
            if (is_int($cond)) {
                if ($cond !== $cookie->idx) continue;
            } else if ($cond instanceof Closure) {
                if (!call_user_func($cond, $cookie)) continue;
            }
            cookie($cookie->name, false);
        }
        return $this;
    }

    public function nextIdx(): ?int
    {
        if (!$this->multiple) throw new Microbe_Exception("Trying to get the next cookie index on a non-multiple cookie");
        return ($cookies = $this->all(modify: false)) ? $cookies[count($cookies) - 1]->idx + 1 : 0;
    }

}

// =============================================================================
