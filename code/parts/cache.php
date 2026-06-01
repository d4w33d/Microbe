<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 *
 * @param  string                       $key        Cache key.
 * @param  int|string|DateInterval|null $ttl        Time-to-live.
 * @param  bool                         $json       Parse and stringify as JSON.
 * @param  bool                         $jsonObject Parse the JSON as an object.
 *                                                  If false, it will be parsed
 *                                                  as an array.
 * @param  string                       $mode       Saving mode (only "file"
 *                                                  is handled yet).
 * @return Microbe_Cached                           Microbe_Cached instance.
 */
function cached(
    string                             $key,
    int | string | DateInterval | null $ttl  = null,
    bool                               $json = false,
    bool                               $jsonObject = true,
    string                             $mode = 'file',
): Microbe_Cached
{
    return new Microbe_Cached($key, $ttl, $json, $jsonObject, $mode);
}

// ---{ Class: Microbe Cached }---

class Microbe_Cached
{

    private string $mode = 'file';
    private ?string $key = null;
    private bool $json = false;
    private bool $jsonObject = true;
    private ?DateInterval $ttl = null;

    private ?string $filePath = null;

    public function __construct(
        string                             $key,
        int | string | DateInterval | null $ttl        = null,
        bool                               $json       = false,
        bool                               $jsonObject = true,
        string                             $mode       = 'file',
    )
    {
        $this->key = $key;
        $this->mode = $mode;
        $this->json = $json;
        $this->jsonObject = $jsonObject;

        if (is_int_val($ttl)) $ttl = new DateInterval('PT' . $ttl . 'S');
        else if (is_string($ttl)) $ttl = str_starts_with($ttl, 'P') ? new DateInterval($ttl) : duration_to_date_interval($ttl);
        else if ($ttl !== null && !($ttl instanceof DateInterval)) throw new Microbe_Exception("Unable to convert the TTL to a DateInterval");
        $this->ttl = $ttl;

        $this->update();
    }

    public function update(): static
    {
        $this->filePath = get_cache_file($this->key . '.cache');
        $this->exists = is_file($this->filePath);
        $this->empty = !$this->exists || filesize($this->filePath) === 0;
        $this->expired = !$this->exists || $this->empty || ($this->ttl !== null && is_expired($this->filePath, $this->ttl));
        return $this;
    }

    public function exists(): bool
    {
        return $this->exists;
    }

    public function empty(): bool
    {
        return $this->empty;
    }

    public function expired(): bool
    {
        return $this->expired;
    }

    public function get(): mixed
    {
        if ($this->expired) return null;
        $raw = file_get_contents($this->filePath);
        return $this->json ? json_decode($raw, !$this->jsonObject) : $raw;
    }

    public function set(mixed $value): static
    {
        if ($value === null) return $this->unset();
        if ($this->json) $value = json_encode($value);
        $value = (string) $value;
        rmkdir(dirname($this->filePath));
        file_put_contents($this->filePath, $value);
        return $this;
    }

    public function unset(): static
    {
        if (is_file($this->filePath)) unlink($this->filePath);
        return $this;
    }

}

// =============================================================================
