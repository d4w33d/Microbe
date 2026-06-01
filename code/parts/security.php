<?php

// =============================================================================
// ---{ Constants }-------------------------------------------------------------

define('MB_SECURITY_LEVEL_SUCCESS',  'success');
define('MB_SECURITY_LEVEL_INFO',     'info');
define('MB_SECURITY_LEVEL_DANGER',   'danger');
define('MB_SECURITY_LEVEL_CRITICAL', 'critical');

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Shortcut to the 'hash' sha256 function call.
 * @param  string $str String to hash.
 * @return string      SHA-256 hashed string.
 */
function sha256(string $str): string
{
    return hash('sha256', $str);
}

/**
 * <USER>
 * Hash some string with specified algorithm, but replace 'cd' with 'xd',
 * to avoid issues when using part of the hashed string as a path folder
 * (e.g. abcdef.jpg -> ab/cd/abcdef.jpg).
 * @param  string $algo Algorithm.
 * @param  string $str  String to hash.
 * @return string       Hashed and "protected" string.
 */
function hashed(string $algo, string $str): string
{
    return str_replace('cd', 'xd', hash($algo, $str));
}

/**
 * <USER>
 * Execute <hashed()> with algorithm SHA-256.
 * @param  string $str  String to hash.
 * @return string       Hashed and "protected" string.
 */
function hashed_sha256(string $str): string
{
    return hashed('sha256', $str);
}

/**
 * <USER>
 * Execute <hashed()> with algorithm SHA-1.
 * @param  string $str  String to hash.
 * @return string       Hashed and "protected" string.
 */
function hashed_sha1(string $str): string
{
    return hashed('sha1', $str);
}

/**
 * <USER>
 * Generate a unique hexadecimal UID string of 64 character maximum.
 * @param  integer $len      Length of the UID (maximum: 64 character).
 * @param  bool    $password Use the <password> function instead of a
 *                           SHA-256 hash. Note: the returned string will be
 *                           [a-z0-9] instead of [a-f0-9].
 * @return string            Generated UID.
 */
function uid(int $len = 64, bool $password = false): string
{
    if ($password) {
        return password(
            len:         $len,
            useSpecials: false,
            changeCase:  false,
            useLetters:  true,
            useNumbers:  true,
        );
    }

    $uid = sha256(uniqid('', true) . mt_rand());
    $uid = str_replace('c', 'abdef'[mt_rand(0, 4)], $uid);
    return substr($uid, 0, $len);
}

/**
 * <USER>
 * Check if some value is formatted as a UID.
 * @param  mixed     $value Value to verify.
 * @param  int|array $len   Expected length (or array of lengths).
 * @return boolean          Does $value seems to be a valid UID or not?
 */
function is_uid(mixed $value, int | array $len = 64): bool
{
    if (!is_string($value)) return false;
    if (!preg_match('/^[a-f0-9]+$/', $value)) return false;
    return in_array(strlen($value), is_array($len) ? $len : [ $len ]);
}

/**
 * <USER>
 * Returns special characters list as a string.
 * @return string
 */
function get_special_chars(): string
{
    return '$*#@_-+=%?';
}

/**
 * <USER>
 * Returns random special character.
 * @return string
 */
function get_random_special_char(): string
{
    $ch = get_special_chars();
    return $ch[mt_rand(0, strlen($ch) - 1)];
}

/**
 * <USER>
 * Generate a pseudo-random password.
 * @param  integer  $len         Length of the password.
 * @param  bool     $useSpecials Insert or not one special character.
 * @param  bool     $changeCase  Use lowercase and uppercase letters.
 * @return string                Generated password.
 */
function password(int $len = 16, bool $useSpecials = true, bool $changeCase = true, bool $useLetters = true, bool $useNumbers = true): string
{
    if ($len < 3) $useSpecials = false;
    $len -= $useSpecials ? 1 : 0;
    // Removed a few numbers and letters for lisibility,
    // and "c" (because "cd" is sometimes problematic).
    $letters = 'abdefghjkmnpqrstuvwxyz';
    $numbers = '23456789';
    $alphabet = '';
    if ($useLetters) $alphabet .= $letters;
    if ($useNumbers) $alphabet .= $numbers;
    $specials = get_special_chars();
    $specialChar = $specials[mt_rand(0, strlen($specials) - 1)];
    $specialLoc = mt_rand(0, $len - 3) + 1;
    $pw = '';
    $alphabetLength = strlen($alphabet);
    for ($i = 0; $i < $len; $i++) {
        if ($useSpecials && $specialLoc === $i) $pw .= $specialChar;
        $char = $alphabet[mt_rand(0, $alphabetLength - 1)];
        if ($changeCase && mt_rand(0, 2) === 1) $char = strtoupper($char);
        $pw .= $char;
    }
    return $pw;
}

/**
 * <USER>
 * Generate a numeric password with the request length.
 * Basically, it just runs the <password> function will only numbers in use.
 * @param  integer $len Password length.
 * @return string       Generated password as a string.
 */
function numeric_password(int $len = 8): string
{
    return password(
        len:         $len,
        useSpecials: false,
        changeCase:  false,
        useLetters:  false,
        useNumbers:  true,
    );
}

/**
 * <USER>
 * Create a syllabic password including numbers and special chars.
 * @param  integer $len Length of the password. Should be odd. If not,
 *                      it will be forced up.
 * @return string       Created password.
 */
function readable_password(int $len = 13): string
{
    if ($len % 2 === 0) $len++;
    $pwd = '';
    for ($i = 0; $i < ($len / 2) - 2; $i++) $pwd .= ucfirst(create_word(2, 2, excludeConfusing: true));
    $pwd .= get_random_special_char();
    $pwd .= mt_rand(10, 99);
    return $pwd;
}

/**
 * <USER>
 * Generate standard conditions for password security.
 * The conditions are configurable through some @app.secure.passwords...
 * configuration entries.
 * @return object The conditions
 */
function get_password_conditions(): object
{
    $defaults = [
        'length'     => [ 'mandatory' => true, 'min' => cfg('~@app.secure.passwords.min_length') ?: 8,                  'explaination' => "be {min} characters minimum;",                      'error' => "Too short"                             ],
        'lowercase'  => [ 'allowed' => true, 'mandatory' => true, 'alphabet' => 'abcdefghijklmnopqrstuvwxyz',           'explaination' => "contains lowercase letters;",                       'error' => "Must contains some lowercase letters"  ],
        'uppercase'  => [ 'allowed' => true, 'mandatory' => true, 'alphabet' => 'ABCDEFGHIJKLMNOPQRSTUVWXYZ',           'explaination' => "contains UPPERCASE letters;",                       'error' => "Must contains some UPPERCASE letters"  ],
        'numbers'    => [ 'allowed' => true, 'mandatory' => true, 'alphabet' => '0123456789',                           'explaination' => "contains some numbers;",                            'error' => "Must contains some numbers"            ],
        'symbols'    => [ 'allowed' => true, 'mandatory' => true, 'alphabet' => ' !"#$%&\'()*+,-./:;<=>?@[\\]^_`{|}~',  'explaination' => "contains some special characters: \"{alphabet}\".", 'error' => "Must contains some special characters" ],
    ];

    foreach ($defaults as $k => $o) {
        foreach ([ 'allowed', 'mandatory', 'alphabet' ] as $prop) {
            if (!array_key_exists($prop, $o)) continue;
            if (($v = cfg('~@app.secure.passwords.' . $k . '.' . $prop)) === null) continue;
        }
        if (array_key_exists('explaination', $o)) $o['explaination'] = t($o['explaination'], $o);
        $o['error'] = t($o['error'], $o);
        $defaults[$k] = (object) $o;
    }

    return (object) $defaults;
}

/**
 * <USER>
 * Check if a given password validate the conditions returned by
 * <get_password_conditions()>.
 * @param  string      $password Password
 * @param  string|null &$reason  A readable and translatable string
 *                               representing the reason for which the
 *                               password is not valid.
 * @return boolean               Is the password valid or not. The reason is
 *                               optionaly passed by reference to the second
 *                               parameter $reason.
 */
function is_secure_password(string $password, ?string &$reason = null): bool
{
    foreach (get_password_conditions() as $condName => $cond) {
        if ($condName === 'length') {
            if (strlen($password) < $cond->min) {
                $reason = $cond->error;
                return false;
            }
            continue;
        }
        $has = (bool) preg_match('/[' . preg_quote($cond->alphabet, '/') . ']/', $password);
        if ((!$cond->allowed && $has) || ($cond->mandatory && !$has)) {
            $reason = $cond->error;
            return false;
        }
    }
    return true;
}

/**
 * <USER>
 * Shortify a UID to a specified length.
 * @param  string|Microbe_Entity|stdClass $uid Full UID, or a Microbe_Entity,
 *                                             or a stdClass containing a uid
 *                                             property.
 * @param  integer                        $len Length to shortify to.
 * @return string                              Shortified UID.
 */
function short_uid(string | Microbe_Entity | stdClass $uid, int $len = 9): string
{
    if ($uid instanceof Microbe_Entity) $uid = $uid->getUid();
    else if (($uid instanceof stdClass) && property_exists($uid, 'uid')) $uid = $uid->uid;
    return substr($uid, 0, $len);
}

/**
 * <USER>
 * Hash a password, using the configuration settings in the section @security.
 * @param  string $password Password to hash.
 * @return string           Hashed password.
 */
function hash_password(string $password): string
{
    if (!($salt = cfg('~@security.salt'))) {
        throw new Microbe_Exception("Trying to hash a password without a salt defined in the configuration.");
    }

    $algo = cfg('~@security.passwords.hash.algorithm') ?: 'sha256';
    $str = cfg('~@security.passwords.hash.format') ?: '{salt}:{password}';
    $str = replace([
        '{salt}'     => $salt,
        '{password}' => $password,
    ], $str);

    return hash($algo, $str);
}

/**
 * <USER>
 * Hash a password, then check if this value corresponds to an
 * entry 'password' of an array, an object or directly to the given string.
 * @param  object|array|string $obj      Object or array containing a
 *                                       key 'password', or a hashed
 *                                       password string.
 * @param  string              $password Password to hash and compare.
 * @return boolean                       Does the password corresponds?
 */
function is_valid_password(object | array | string $obj, string $password): bool
{
    if (is_object($obj)) {
        if (!property_exists($obj, 'password')) {
            throw new Microbe_Exception("The 'password' property doesn't exists in the given object");
        }
        $obj = $obj->password;
    } else if (is_array($obj)) {
        if (!array_key_exists('password', $obj)) {
            throw new Microbe_Exception("The 'password' key doesn't exists in the given array");
        }
        $obj = $obj['password'];
    } else if (!is_string($obj)) {
        throw new Microbe_Exception("Unable to handle the given object for password comparision");
    }

    return hash_password($password) === $obj;
}

/**
 * <USER>
 * Compute password strength score.
 * @param  string $password        Password to check.
 * @param  int    $minLength       Minimum length (default 6).
 * @param  int    $excellentLength Excellent length (default 20).
 * @return float                   Score as a float between 0 (very bad) and
 *                                 1 (very good).
 */
function compute_password_score(string $password, int $minLength = 6, int $excellentLength = 20): float
{
    if (!$password) return 0;
    $len = strlen($password);

    $has = [
        'lower'   => (bool) preg_match('/[a-z]/', $password),
        'upper'   => (bool) preg_match('/[A-Z]/', $password),
        'digit'   => (bool) preg_match('/\d/', $password),
        'special' => (bool) preg_match('/[^A-Za-z0-9]/', $password),
    ];

    $nbTypes = count($has);
    $nbTypesUsed = count(array_filter(array_values($has)));

    $lengthScore = 0;
    if ($len < $minLength) return 0;
    $lengthScore = $len >= $excellentLength ? 1 : (($len - $minLength) / ($excellentLength - $minLength));

    $typeScore = $nbTypesUsed / $nbTypes;
    $finalScore = (0.7 * $lengthScore) + (0.3 * $typeScore);
    $bonus = ($len >= ($excellentLength * 1.2) && $nbTypesUsed === $nbTypes) ? 0.05 : 0;
    return max(0, min(1, $finalScore + $bonus));
}

/**
 * <USER>
 * Performs a global security check and returns an array containing the result
 * of each item of control.
 * @return array Items of control with their success or error message and
 *               risk level.
 */
function security_check(): array
{
    $performExternalAccess = function(string $url, string $label, ?string $regex = null, string $maxLevel = MB_SECURITY_LEVEL_CRITICAL): Closure
    {
        return function() use ($url, $label, $regex, $maxLevel): array
        {
            $raw = curl_get(url($url, host: true)) ?: '';
            if (curl_last_info('http_code') === 200) {
                if (preg_match($regex, $raw)) return [ $maxLevel, "The {$label} is readable from outside." ];
                return [ MB_SECURITY_LEVEL_DANGER, "The {$label} seems to be readable (HTTP code 200 returned)." ];
            } else return [ MB_SECURITY_LEVEL_SUCCESS, "The {$label} seems to be protected." ];
        };
    };

    $items = [];

    $items[] = [ 'name' => 'config', 'perform' => $performExternalAccess(url: '/config.json', label: "configuration file", regex: '/\{.*"app"\s*:/ms') ];
    foreach (array_merge([ 'user'], get_valid_env()) as $env) {
        if (!is_file(get_path('/config-' . $env . '.json'))) continue;
        $items[] = [ 'name' => 'config-' . $env, 'perform' => $performExternalAccess(url: '/config-' . $env . '.json', label: "configuration file", regex: '/\{/ms') ];
    }
    $items[] = [ 'name' => 'env', 'perform' => $performExternalAccess(url: '/ENV', label: "environment file", regex: '/(' . implode('|', get_valid_env()) . ')/', maxLevel: MB_SECURITY_LEVEL_DANGER) ];

    $results = [];
    foreach ($items as $item) {
        list($level, $msg) = $item['perform']();
        $results[] = (object) [
            'name'    => $item['name'],
            'level'   => $level,
            'details' => $msg,
        ];
    }

    return $results;
}

// =============================================================================
// ---{ Listeners }-------------------------------------------------------------

listen('register_cfg_snippets', function(): array
{
    return [
        'security' => [
            'salt'      => password(32),
            'passwords' => [
                'hash' => [
                    'algorithm' => 'sha256',
                    'format'    => '{salt}:{password}',
                ],
            ],
        ],
    ];
});

listen('init', function(): void
{
    register_cli_action(
        bundle:      'core',
        name:        'hash_password',
        description: "Hash Given Password Using Microbe's Core Hashing Function",
        opts:        [],
        func:        function(object $opts): void
        {
            if (!($pwd = cli_prompt("Password: "))) {
                cli_error("No password: Aborting.");
                return;
            }
            cli_write("Hashed Password: " . hash_password($pwd));
        },
    );
});

// =============================================================================
