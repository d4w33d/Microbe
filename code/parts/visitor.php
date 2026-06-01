<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Try to guess the browser and the version based on user agent.
 * @param  string      $userAgent User agent.
 * @return object|null            Object containing the name and the version of
 *                                the bowser.
 */
function guess_browser_by_user_agent(string $userAgent): ?object
{
    $browsers = [
        'Edge'    => 'Edg\/([\d\.]+)',
        'Chrome'  => 'Chrome\/([\d\.]+)',
        'Firefox' => 'Firefox\/([\d\.]+)',
        'Safari'  => 'Version\/([\d\.]+).*Safari',
        'Opera'   => 'OPR\/([\d\.]+)',
        'IE'      => 'MSIE ([\d\.]+)|Trident\/.*rv:([\d\.]+)',
    ];
    foreach ($browsers as $name => $pattern) {
        if (preg_match("/$pattern/i", $userAgent, $m)) {
            return (object) [ 'name' => $name, 'version' => $m[1] ?? $m[2] ?? null ];
        }
    }
    return null;
}
