<?php

// =============================================================================
// ---{ Global Variables }------------------------------------------------------

/**
 * Global variable containing registered listeners for events management system.
 * @var array
 */
$_LISTENERS = [];

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Register an event listener. The optional third argument is custom data
 * array which will be passed to the function as second argument when
 * the event is fired.
 * @param  string  $evt  Event name.
 * @param  Closure $func Listener function, which will accept two arguments:
 *                       event data and listener data.
 * @param  array   $data Listener data.
 */
function listen(string $evt, Closure $func, array $data = []): void
{
    global $_LISTENERS;
    if (!array_key_exists($evt, $_LISTENERS)) $_LISTENERS[$evt] = [];
    $_LISTENERS[$evt][] = (object) [
        'func' => $func,
        'data' => $data,
    ];
}

/**
 * <USER>
 * Dispatch an event to all the listeners, with optional event data.
 * @param  string $evt  Event name.
 * @param  array  $data Optional event data.
 * @return array|null   Array of all registered listeners results (not null).
 */
function dispatch(string $evt, array $data = []): ?array
{
    global $_LISTENERS;
    $results = [];
    if (!array_key_exists($evt, $_LISTENERS)) return $results;
    foreach ($_LISTENERS[$evt] as $listener) {
        $r = call_user_func($listener->func, $data, $listener->data);
        if ($r !== null) $results[] = $r;
    }
    return $results;
}

/**
 * Global variable containing registered listeners for
 * filters management system.
 * @var array
 */
$_FILTERS = [];

/**
 * <USER>
 * Register a filter listener.
 * @param  string  $filter Filter name.
 * @param  Closure $func   Listener function, which will accept the data to
 *                         filter as unique argument.
 */
function register_filter(string $filter, Closure $func): void
{
    global $_FILTERS;
    if (!array_key_exists($filter, $_FILTERS)) $_FILTERS[$filter] = [];
    $_FILTERS[$filter][] = (object) [ 'func' => $func ];
}

/**
 * <USER>
 * Dispatch a filter request to all the filter listeners.
 * @param  string $filter Filter name.
 * @param  mixed  $data   Data which will be send and processed by the
 *                        listeners.
 * @param  array  $args   Additional arguments, unchanged on each filter call.
 * @return mixed          Result of the consecutive filterings.
 */
function filter(string $filter, mixed $data = null, array $args = []): mixed
{
    global $_FILTERS;
    if (!array_key_exists($filter, $_FILTERS)) return $data;
    foreach ($_FILTERS[$filter] as $listener) {
        $data = call_user_func($listener->func, $data, $args);
    }
    return $data;
}

/**
 * <USER>
 * Register an entry of something in memory (e.g. an array or an object
 * describing anything). The collection will be able to be retrieved with
 * <get_registered_things()>.
 * @param  string      $thing Thing type.
 * @param  mixed       $data  Data to store.
 * @param  string|null $key   Save thing in array as $key. If null, the things
 *                            array will be considerated as a numeric array.
 */
function register_thing(string $thing, mixed $data, ?string $key = null): void
{
    $things = get_registered_things($thing);
    if ($key !== null) $things[$key] = $data;
    else $things[] = $data;
    cfg('@core.things.' . $thing, $things);
}

/**
 * <USER>
 * Remove all registered things of type $thing.
 * @param  string $thing Thing type.
 */
function unregister_things(string $thing): void
{
    cfg('@core.things.' . $thing, []);
}

/**
 * <USER>
 * Returns all registered things of type $thing.
 * @param  string $thing Thing type.
 * @return array         All registered things of given type.
 */
function get_registered_things(string $thing): array
{
    return cfg('~@core.things.' . $thing) ?: [];
}

/**
 * <USER>
 * Returns the registered thing where its array or object's $prop equals $value.
 * @param  string          $thing Thing type.
 * @param  string|null     $prop  Thing's property name.
 * @param  mixed           $value Property value.
 * @param  int|string|null $key   Key of the thing.
 * @param  int|null        $index Index of the thing. The array will be forced
 *                                as a numerical-array.
 * @return mixed                  The thing, or null if nothing found.
 */
function get_registered_thing(
    string              $thing,
    ?string             $prop  = null,
    mixed               $value = null,
    int | string | null $key   = null,
    ?int                $index = null,
): mixed
{
    $things = get_registered_things($thing);
    if ($key !== null) return $things[$key] ?? null;
    if ($index !== null) return array_values($things)[$index] ?? null;
    if ($prop !== null) {
        foreach ($things as $t) {
            $v = null;
            if (is_object($t)) $v = $t->$prop ?? null;
            else if (is_array($t)) $v = $t[$prop] ?? null;
            if ($v === $value) return $t;
        }
        return null;
    }
    return null;
}

// ---{ Class: Microbe Event Emitter }---

abstract class Microbe_Event_Emitter
{

    protected array $listeners = [];

    public function on(string $action, Closure $func): static
    {
        if (!array_key_exists($action, $this->listeners)) $this->listeners[$action] = [];
        $this->listeners[$action][] = $func;
        return $this;
    }

    public function fire(
        string $action,
        array  $args   = [],
        bool   $filter = false,
        mixed  $value  = null,
    ): mixed
    {
        $results = [];
        foreach ($this->listeners[$action] ?? [] as $func) {
            $value = call_user_func_array($func, $filter ? array_merge([ $value ], $args) : $args);
            if (!$filter) $results[] = $value;
        }
        return $filter ? $value : $results;
    }

    public function filter(string $name, mixed $value): mixed
    {
        return $this->fire(action: $name, filter: true, value: $value);
    }
}

// =============================================================================
