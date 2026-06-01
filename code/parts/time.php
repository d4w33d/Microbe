<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Returns the <microtime> in microseconds (and not in seconds).
 * @return int The microseconds timestamp.
 */
function microseconds(): int
{
    return (int) (microtime(true) * 1000000);
}

/**
 * <USER>
 * Returns the time since the beginning of the script.
 * @return float Current time in seconds.
 */
function script_time(): float
{
    return microtime(true) - MB_SCRIPT_START;
}

/**
 * <USER>
 * Returns app timezone.
 * @return DateTimeZone App timezone
 */
function get_app_timezone(): DateTimeZone
{
    return new DateTimeZone(cfg('~@time.timezone') ?: 'UTC');
}

/**
 * <USER>
 * Returns app timezone name.
 * @return string App timezone name if it's a valid one.
 */
function get_app_timezone_name(): ?string
{
    return ($dtz = get_app_timezone()) ? $dtz->getName() : null;
}

/**
 * <USER>
 * Returns system timezone.
 * @return DateTimeZone System timezone
 */
function get_system_timezone(): DateTimeZone
{
    return new DateTimeZone(MB_DEFAULT_TIMEZONE);
}

/**
 * <USER>
 * Returns system timezone name.
 * @return string System timezone name if it's a valid one.
 */
function get_system_timezone_name(): ?string
{
    return ($dtz = get_system_timezone()) ? $dtz->getName() : null;
}

/**
 * <USER>
 * Returns all timezones supported by system.
 * @param  bool  $onlyIdentifiers Returns a simple array of strings names
 *                                instead of detailled info.
 * @return array                  An array of objects describing the timezone.
 */
function get_timezones(bool $onlyIdentifiers = false): array
{
    $identifiers = DateTimeZone::listIdentifiers(DateTimeZone::ALL);
    if ($onlyIdentifiers) return $identifiers;

    $now = new DateTime('now', new DateTimeZone('UTC'));
    $timezones = array_map(function(string $tzIdentifier) use ($now): object
    {
        return (object) [
            'name'     => ($dtz = new DateTimeZone($tzIdentifier))->getName(),
            'location' => $dtz->getLocation(),
            'offset'   => (object) [
                'seconds' => $offset = $dtz->getOffset($now),
                'short'   => $pretty = ($offset < 0 ? '-' : '+') . gmdate('H:i', abs($offset)),
                'long'    => 'UTC' . $pretty,
            ],
        ];
    }, $identifiers);

    usort($timezones, function(object $a, object $b): int
    {
        if ($a->offset->seconds < $b->offset->seconds) return -1;
        if ($a->offset->seconds > $b->offset->seconds) return 1;
        if ($a->name < $b->name) return -1;
        if ($a->name > $b->name) return 1;
        return 0;
    });

    return $timezones;
}

/**
 * <USER>
 * Check if the given identifier is a valid timezone.
 * @param  string  $identifier Probably a timezone name.
 * @return boolean             True if the timezone exists. Else, false.
 */
function is_valid_timezone(string $identifier): bool
{
    return in_array($identifier, get_timezones(onlyIdentifiers: true));
}

/**
 * <USER>
 * Echo the current year.
 */
function _year(): void
{
    echo (new DateTime())->format('Y');
}

/**
 * <USER>
 * Convert a number of seconds to hh:mm:ss
 * @param  int    $seconds          Number of seconds.
 * @param  bool   $overtimeSentence String returned if $seconds is over a day.
 * @return string                   Total time with format "hh:mm:ss".
 */
function seconds_to_duration(int $seconds, string $overtimeSentence = "An eternity"): string
{
    $seconds = round($seconds);
    if ($seconds > 24 * 3600) return t($overtimeSentence);
    return sprintf('%02d:%02d:%02d', $seconds / 3600, floor($seconds / 60) % 60, $seconds % 60);
}

/**
 * <USER>
 * Parse a duration string, suffixed with one of those units:
 * s(econds), m(inutes), h(ours), d(ays), w(eeks), y(ears).
 * Note that there is no monthes unit for two reasons: first, there can be a
 * conflict between minutes and monthes, and secondary, the conversion from
 * month to seconds may vary a lot because of the variability of the
 * month duration, from 28 to 31 days.
 * @param  int|string           $str            Some string suffixed with a
 *                                              time unit.
 * @param  bool                 $asDateInterval Returns DateInterval instance
 *                                              instead of an integer of
 *                                              seconds.
 * @return integer|DateInterval                 Duration, in seconds
 *                                              or DateInterval instance.
 */
function parse_duration(int | string $str, bool $asDateInterval = false): int
{
    if (is_integer($str)) return $str;

    $regex = '/^(?<int>[0-9]+(?<dec>\.[0-9]+)?)(?<unit>[smhdwy])?$/i';
    if (!preg_match($regex, trim((string) $str), $matches)) {
        return 0;
    }

    $t = ((float) $matches['int']) + ((float) ('0.' . $matches['dec']));
    $s = match (strtolower($matches['unit'])) {
        's'     => (int) $t,                        // Seconds
        'm'     => (int) ($t * 60),                 // Minutes
        'h'     => (int) ($t * 3600),               // Hours
        'd'     => (int) ($t * 24 * 3600),          // Days
        'w'     => (int) ($t * 7 * 24 * 3600),      // Weeks
        'y'     => (int) ($t * 365.25 * 24 * 3600), // Years
        default => (int) $t,                        // Default: Seconds
    };

    return $asDateInterval ? new DateInterval('PT' . $s . 'S') : $s;
}

/**
 * <USER>
 * Parse a duration string, and create the corresponding PHP DateInterval.
 * @param  int|string $str Some string suffixed with a time unit.
 * @return DateInterval    The DateInterval corresponding to the duration.
 */
function duration_to_date_interval(int | string $str): DateInterval
{
    return new DateInterval('PT' . parse_duration($str) . 'S');
}

/**
 * <USER>
 * Check if a file or a date is expired, based on given time-to-live.
 * @param  string|int|DateTimeInterface     $what Some date to check. Could be:
 *                                                  - DateTimeInterface instance;
 *                                                  - UNIX timestamp;
 *                                                  - String date,
 *                                                    as "Y-m-d H:i:s";
 *                                                  - File path (the modified
 *                                                    time will be checked).
 * @param  string|int|DateInterval          $ttl  Time-to-live. Could be:
 *                                                  - Seconds as an integer;
 *                                                  - DateInterval instance;
 *                                                  - The DateInterval duration
 *                                                    string (as "P...T...").
 * @return boolean                                Is the date expired or not?
 */
function is_expired(string | int | DateTimeInterface $what, string | int | DateInterval $ttl): bool
{
    if (is_string($what) && !is_int_val($what)) {
        if (is_valid_datetime($what)) $what = strtotime($what);
        else if (is_file($what)) $what = filemtime($what);
        else throw new Microbe_Exception("Unable to check expiration on unknown subject");
    } else if ($what instanceof DateTimeInterface) $what = $what->format('U');

    if (!is_int_val($what)) throw new Microbe_Exception("Unable to fetch UNIX time in seconds from the expiration subject");
    $what = (int) $what;

    if (is_int_val($ttl)) $ttl = new DateInterval('PT' . $ttl . 'S');
    else if (is_string($ttl)) $ttl = str_starts_with($ttl, 'P') ? new DateInterval($ttl) : duration_to_date_interval($ttl);
    else if (!($ttl instanceof DateInterval)) throw new Microbe_Exception("Unable to convert the TTL to a DateInterval");

    $expires = new DateTime('@' . $what);
    $expires->add($ttl);
    return $expires < (new DateTime());
}

/**
 * <USER>
 * Compute the time ago since a specified date and time.
 * @param  DateTimeInterface|string|int $dt Date and time of the event.
 *                                          Can be a DateTime object, a string
 *                                          which can be parsed by the DateTime
 *                                          constructor, or a timestamp as
 *                                          an integer.
 * @param  DateTime|null       $now         Now DateTime. Default, now.
 * @param  bool                $full        Don't filter the fields (YMWDhms),
 *                                          so returns all the fields, even if
 *                                          they're zero.
 * @param  int|string          $limit       Returns null if the delay is above
 *                                          the given limit, represented as
 *                                          seconds or a string duration.
 * @param  bool                $lower       Returns lowercased.
 * @param  bool                $translate   Returns lowercased.
 * @param  string              $textFormat  Text format to be returned.
 * @return string                           Time ago as a string of elapsed
 *                                          hours, minutes, etc.
 */
function get_time_ago(
    DateTimeInterface | string | int $dt,
    DateTimeInterface                $now        = null,
    bool                             $full       = false,
    int | string                     $limit      = null,
    bool                             $lower      = false,
    bool                             $translate  = true,
    string                           $textFormat = '{time} ago',
): ?string
{
    if ($now === null) $now = new DateTime();
    if (!($dt instanceof DateTimeInterface)) $dt = new DateTime(is_int($dt) ? '@' . $dt : $dt);

    if ($limit !== null) {
        if ($now->format('U') - $dt->format('U') >= parse_duration($limit)) {
            return null;
        }
    }

    $diff = $now->diff($dt);

    $t = $translate
        ? fn(string $s, array $args = []): string => t($s, $args)
        : fn(string $s, array $args = []): string => t($s, $args, fromLocale: $tl = get_translations_locale(), toLocale: $tl);

    $str = array(
        'y' => [ $t('year'),   $t('years')   ],
        'm' => [ $t('month'),  $t('months')  ],
        'w' => [ $t('week'),   $t('weeks')   ],
        'd' => [ $t('day'),    $t('days')    ],
        'h' => [ $t('hour'),   $t('hours')   ],
        'i' => [ $t('minute'), $t('minutes') ],
        's' => [ $t('second'), $t('seconds') ],
    );

    $parts = [];
    foreach ($str as $k => $v) if (property_exists($diff, $k)) $parts[$k] = $diff->$k;
    $parts = (object) $parts;
    $parts->w = floor($parts->d / 7);
    $parts->d -= $parts->w * 7;

    foreach ($str as $k => &$v) {
        if (!$parts->$k) {
            unset($str[$k]);
            continue;
        }
        $v = $parts->$k . ' ' . ($parts->$k > 1 ? $v[1] : $v[0]);
    }

    if (!$full) $str = array_slice($str, 0, 1);

    if (!$str) $str = $t('Just now');
    else $str = $t($textFormat, [ 'time' => implode(', ', $str) ]);
    if ($lower) $str = mb_strtolower($str);
    return $str;
}

/**
 * <USER>
 * Execute a reversed <get_time_ago()> with labels adapted for the time
 * remaining between the two dates.
 * @param  DateTimeInterface|string|int $dt Date and time of the event.
 *                                          Can be a DateTime object, a string
 *                                          which can be parsed by the DateTime
 *                                          constructor, or a timestamp as
 *                                          an integer.
 * @param  DateTime|null       $now         Now DateTime. Default, now.
 * @param  bool                $full        Don't filter the fields (YMWDhms),
 *                                          so returns all the fields, even if
 *                                          they're zero.
 * @param  int|string          $limit       Returns null if the delay is above
 *                                          the given limit, represented as
 *                                          seconds or a string duration.
 * @param  bool                $lower       Returns lowercased.
 * @param  bool                $translate   Returns lowercased.
 * @return string                           Time ago as a string of elapsed
 *                                          hours, minutes, etc.
 */
function get_time_remaining(
    DateTimeInterface | string | int $dt,
    DateTimeInterface                $now       = null,
    bool                             $full      = false,
    int | string                     $limit     = null,
    bool                             $lower     = false,
    bool                             $translate = true,
): ?string
{
    if (!($dt instanceof DateTimeInterface)) $dt = new DateTime(is_int($dt) ? '@' . $dt : $dt);
    if ($now === null) $now = new DateTime();

    $oldNow = $now;
    $now = $dt;
    $dt = $oldNow;

    return get_time_ago(
        dt:         $dt,
        now:        $now,
        full:       $full,
        limit:      $limit,
        lower:      $lower,
        translate:  $translate,
        textFormat: 'Within {time}',
    );
}

/**
 * <USER>
 * Returns the month names and numbers as an array containing monthes
 * as objects, with those information:
 *   - Index: integer number of the month (1 for january, 12 for december).
 *   - $month->num: padded month number.
 *   - $month->name: long name translated (e.g. February).
 *   - $month->short: short name translated (e.g. Feb).
 * @param  string|null $locale Locale name.
 * @return array               Monthes.
 */
function get_monthes(?string $locale = null): array
{
    if ($locale === null) $locale = get_current_app_locale()->standard_code;

    $long = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, null, 'LLLL');
    $short = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, null, 'LLL');

    $monthes = [];
    for ($i = 1; $i <= 12; $i++) {
        $m = (object) [
            'num'   => str_pad((string) $i, 2, '0', STR_PAD_LEFT),
            'name'  => $long->format($t = mktime(12, 12, 12, $i, 12)),
            'short' => $short->format($t),
        ];
        $monthes[$i] = $m;
    }

    return $monthes;
}

/**
 * <USER>
 * Get the month name based on its index.
 * @param  DateTimeInterface|string|int $idx    Index of the month ('08' or 8).
 * @param  bool                         $short  Returns the short name if true.
 *                                              Else, the long.
 * @param  string|null                  $locale Locale name.
 * @return string                               Month name.
 */
function get_month_name(DateTimeInterface | string | int $idx, bool $short = false, ?string $locale = null): string
{
    if ($idx instanceof DateTimeInterface) $idx = (int) $idx->format('n');
    return get_monthes($locale)[(int) $idx]->{$short ? 'short' : 'name'};
}

/**
 * <USER>
 * Returns the days of week names and numbers as an array containing days
 * as objects, with those information:
 *   - Index: integer number of the day (1 for monday, 7 for sunday).
 *   - $day->num: integer day number.
 *   - $day->name: long name translated (e.g. Wednesday).
 *   - $day->short: short name translated (e.g. Wed).
 * @param  string|null $locale Locale name.
 * @return array       Days.
 */
function get_days(?string $locale = null): array
{
    if ($locale === null) $locale = get_current_app_locale()->standard_code;

    $long = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, null, 'EEEE');
    $short = new IntlDateFormatter($locale, IntlDateFormatter::NONE, IntlDateFormatter::NONE, null, null, 'EEE');

    $dt = new DateTime();
    $dayOfWeek = $dt->format('N');
    if ($dayOfWeek > 1) $dt->sub(new DateInterval('P' . ($dayOfWeek - 1) . 'D'));

    $days = [];
    for ($i = 1; $i <= 7; $i++) {
        $d = (object) [
            'num'   => $i,
            'name'  => $long->format($dt),
            'short' => $short->format($dt),
        ];
        $d->name = function_exists('mb_ucfirst') ? mb_ucfirst($d->name) : ucfirst($d->name);
        $d->short = function_exists('mb_ucfirst') ? mb_ucfirst($d->short) : ucfirst($d->short);
        $days[$i] = $d;
        $dt->add(new DateInterval('P1D'));
    }

    return $days;
}

/**
 * <USER>
 * Get the day name based on its index.
 * @param  DateTimeInterface|string|int $idx    Index of the day ('5' or 5).
 * @param  bool                         $short  Returns the short name if true. Else, the long.
 * @param  string|null                  $locale Locale name.
 * @return string                               Day name.
 */
function get_day_name(DateTimeInterface | string | int $idx, bool $short = false, ?string $locale = null): string
{
    if ($idx instanceof DateTimeInterface) $idx = (int) $idx->format('N');
    return get_days($locale)[$idx]->{$short ? 'short' : 'name'};
}

/**
 * <USER>
 * Check if a given value is a proper string representing a PHP date/time.
 * @param  mixed   $value  Value to be checked.
 * @param  string  $format The format, with similar notation as PHP DateTime's
 *                         format function.
 * @return boolean         Is $value a valid representation of $format, or not?
 */
function is_valid_datetime(mixed $value, string $format = 'Y-m-d H:i:s'): bool
{
    if (!is_string($value) || !$value) return false;
    $format = preg_quote($format, '/');
    $format = str_replace([ 'Y' ], '#_#{4}', $format);
    $format = str_replace([ 'y', 'm', 'd', 'H', 'i', 's' ], '#_#{2}', $format);
    $format = str_replace([ 'j', 'n', 'G' ], '#_#{1,2}', $format);
    $format = str_replace('#_#', '\\d', $format);
    return (bool) preg_match('/^' . $format . '$/', $value);
}

/**
 * <USER>
 * Build a DateTime from two form inputs: YYYY-MM-DD and hh:mm:ss.
 * @param  mixed  $date Form date which should be YYYY-MM-DD.
 * @param  mixed  $time Form time which should be hh:mm:ss.
 * @return DateTime     Resulting DateTime object, or null if invalid values.
 */
function build_datetime_from_form(mixed $date = false, mixed $time = '00:00:00'): ?DateTime
{
    if ($date === false) $date = (new DateTime())->format('Y-m-d');
    if (!$date) return null;
    if (!is_valid_datetime($date, format: 'Y-m-d')) return null;
    if (!is_valid_datetime($time, format: 'H:i:s') && !is_valid_datetime($time, format: 'H:i')) $time = '00:00:00';
    return new DateTime($date . ' ' . $time);
}

/**
 * <USER>
 * Format the date based on given locale.
 * @param  string|int|DateTimeInterface $dt         Date.
 * @param  string                       $locale     Locale.
 * @param  string                       $format     Custom IntlDateFormatter
 *                                                  pattern, or:
 *                                                      - short
 *                                                      - medium
 *                                                      - long
 *                                                      - full
 * @param  bool|null                    $removeYear If true, year will be
 *                                                  removed. If null, year
 *                                                  will be removed if it's
 *                                                  different from current
 *                                                  year.
 * @param  string                       $timezone   Timezone. If null, the one
 *                                                  got from $dt's DateTime
 *                                                  instance will be used.
 * @return string                                   Formatted date.
 */
function format_date(
    string | int | DateTimeInterface $dt,
    string                           $locale      = 'fr_FR',
    string                           $format      = 'long',
    ?bool                            $removeYear  = false,
    ?string                          $timezone    = null,
): string
{
    if (is_int($dt)) $dt = new DateTime('@' . $dt);
    else if (is_string($dt)) $dt = new DateTime($dt);

    if (!$timezone) $timezone = $dt->getTimezone()->getName();

    $formats = [
        'short'  => IntlDateFormatter::SHORT,
        'medium' => IntlDateFormatter::MEDIUM,
        'long'   => IntlDateFormatter::LONG,
        'full'   => IntlDateFormatter::FULL,
    ];

    if (array_key_exists($format, $formats)) {
        $formatter = new IntlDateFormatter(
            $locale,
            $formats[$format],
            IntlDateFormatter::NONE,
            $timezone
        );
    } else {
        $formatter = new IntlDateFormatter(
            $locale,
            IntlDateFormatter::NONE,
            IntlDateFormatter::NONE,
            $timezone,
            null,
            $format
        );
    }

    $formatted = $formatter->format($dt);

    if ($removeYear !== false) {
        $dtYear = $dt->format('Y');
        if ($removeYear === true || ($removeYear === null && $dtYear === (new DateTime())->format('Y'))) {
            $formatted = str_replace([ ', ' . $dtYear, ',' . $dtYear, $dtYear ], '', $formatted);
        }
    }

    return trim(preg_replace('/\s+/', ' ', $formatted));
}

/**
 * <USER>
 * Echo the formatted date based on given locale.
 * @param  string|int|DateTime $dt     Date.
 * @param  string              $locale Locale.
 */
function _format_date(
    string | int | DateTimeInterface $dt,
    string                           $locale   = 'fr_FR',
    string                           $format   = 'long',
    ?string                          $timezone = null,
): void
{
    echo format_date(
        dt:       $dt,
        locale:   $locale,
        format:   $format,
        timezone: $timezone,
    );
}

/**
 * <USER>
 * Returns an object containing the weeks and days of weeks of a given month,
 * in order to be used to display a calendar.
 * @param  int|string   $year         Requested year.
 * @param  int|string   $month        Requested month.
 * @param  bool         $sundayFirst  Is sunday first day of the week. If
 *                                    false (default), then monday is.
 * @param  null|Closure $dataModifier Function executed for each day, to
 *                                    populate the "data" property.
 * @param  string       $url          URL for previous/current/next monthes
 *                                    information.
 * @return object                     Object describing the month.
 */
function get_calendar_month_days(
    int | string $year,
    int | string $month,
    bool         $sundayFirst  = false,
    ?Closure     $dataModifier = null,
    string       $url          = './?year={{year}}&month={{month}}',
): object
{
    $year = (int) $year;
    $month = (int) $month;

    $dt = new DateTime($year . '-' . str_pad((string) $month, 2, '0', STR_PAD_LEFT) . '-01 00:00:00');
    $startDateTime = clone $dt;
    $daysInMonth = (int) $dt->format('t');

    $firstDayOfMonth = ((int) $dt->format('N')) - 1;
    if ($sundayFirst) {
        $firstDayOfMonth--;
        if ($firstDayOfMonth === -1) $firstDayOfMonth = 6;
    }

    if ($firstDayOfMonth > 0) $dt->sub(new DateInterval('P' . $firstDayOfMonth . 'D'));
    $interval = new DateInterval('P1D');

    $processDay = function(DateTime $dt) use ($month, $dataModifier): object
    {
        $m = (int) $dt->format('m');
        $day = (object) [
            'dt'                => $thisDateTime = clone $dt,
            'day_name'          => $dayName = strtolower($dt->format('D')),
            'is_weekend'        => in_array($dayName, [ 'sat', 'sun' ]),
            'is_previous_month' => $m < $month,
            'is_current_month'  => $m === $month,
            'is_next_month'     => $m > $month,
            'data'              => null,
        ];
        if ($dataModifier) $day->data = $dataModifier($day);
        return $day;
    };

    $weeks = [];
    $week = [];

    for ($d = 1; $d <= $firstDayOfMonth; $d++) {
        $week[] = $processDay($dt);
        $dt->add($interval);
        if (count($week) >= 7) { $weeks[] = $week; $week = []; }
    }
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $week[] = $processDay($dt);
        $dt->add($interval);
        if (count($week) >= 7) { $weeks[] = $week; $week = []; }
    }
    if ($week) {
        while (count($week) < 7) {
            $week[] = $processDay($dt);
            $dt->add($interval);
        }
        $weeks[] = $week;
    }

    $out = (object) [
        'weeks'   => $weeks,
        'monthes' => (object) [],
    ];

    foreach ([ [ -1, 'previous' ], [ 0, 'current' ], [ 1, 'next' ] ] as list($monthOffset, $monthKey)) {
        $monthDateTime = clone $startDateTime;
        $monthInterval = new DateInterval('P' . abs($monthOffset) . 'M');
        if ($monthOffset < 1) $monthDateTime->sub($monthInterval);
        else $monthDateTime->add($monthInterval);
        $out->monthes->{$monthKey} = (object) [
            'dt'  => clone $monthDateTime,
            'url' => replace([ '{{month}}' => $monthDateTime->format('m'), '{{year}}' => $monthDateTime->format('Y') ], $url),
        ];
    }

    return $out;
}

// =============================================================================
