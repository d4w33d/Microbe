<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Check if the email address is valid.
 * @param  mixed   $email String supposed to be an email address.
 * @return boolean        If it seems to be a proper email address, true.
 */
function is_valid_email_address(mixed $email): bool
{
    if (!$email || !is_scalar($email)) return false;
    return (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

/**
 * <USER>
 * Parse several email addresses using <parse_email_address> for each one.
 * @param  string $str String to parse.
 * @return array       Array of parsed email address as objects.
 */
function parse_email_addresses(string $str): array
{
    return array_values(array_filter(array_map(function(string $s): ?object
    {
        $e = parse_email_address($s);
        return $e->addr ? $e : null;
    }, explode(',', $str))));
}

/**
 * <USER>
 * Parse an email address, with optionally the name and the address.
 * @param  string $str String to parse.
 * @return object      Parsed email address as an object.
 */
function parse_email_address(string $str): object
{
    $e = (object) [ 'addr' => trim($str), 'name' => null ];
    if (preg_match('/^(?<name>.*)<(?<addr>.+)>$/', $e->addr, $m)) {
        $e->addr = $m['addr'];
        $e->name = trim($m['name'] ?: '') ?: null;
    }
    $e->elements = (object) [];
    list($e->elements->user, $e->elements->domain) = explode('@', $e->addr);
    $e->formatted = $e->name ? $e->name . ' <' . $e->addr . '>' : $e->addr;
    return $e;
}

/**
 * <USER>
 * Parse email address, get its left part, and optionnaly tries to format
 * the username.
 * @param  string      $str       String to parse (perhaps an email address).
 * @param  bool        $formatted Tries to format the username.
 * @return string|null            User name, if found.
 */
function get_email_address_user_name(string $str, bool $formatted = true): ?string
{
    $e = parse_email_address($str);
    if (!($name = ($e->name ?: $e->elements->user))) return null;
    if ($formatted) return $name;
    $name = strtolower($name);
    $name = preg_replace('/\d+$/', '', $name);
    $name = str_replace([ '.', '_', '-', '+' ], ' ', $name);
    $name = preg_replace('/\s+/', ' ', $name);
    $name = ucwords($name);
    return trim($name);
}

/**
 * <USER>
 * Obfuscate the characters of an email address except the two firsts of
 * the username and the @... part.
 * @param  string $email Email address
 * @return string        Obfuscated email address.
 */
function obfuscate_email_address(string $email, string $replacement = '***'): string
{
    list($left, $right) = explode('@', $email);
    if (strlen($left) <= 2) $left = $replacement;
    else $left = substr($left, 0, 2) . $replacement;
    return $left . '@' . $right;
}

/**
 * <USER>
 * Register an email carrier.
 * @param  string  $name Name of the carrier.
 * @param  Closure $func Function called when sending an email through
 *                       this carrier. $func should take three arguments:
 *                         - object $cfg
 *                         - object $opts
 *                         - string $body
 *                         - array $embeddedImages
 */
function register_email_carrier(string $name, Closure $func): void
{
    $carriers = cfg('~@emails.registered_carriers') ?: [];
    $carriers[$name] = (object) [ 'name' => $name, 'func' => $func ];
    cfg('@emails.registered_carriers', $carriers);
}

/**
 * <USER>
 * Returns the email carrier object currently in use.
 * @return object|null Object givin the callback function of the carrier.
 */
function get_current_email_carrier(): ?object
{
    if (!($current = cfg('~@emails.current_carrier'))) return null;
    $carriers = cfg('~@emails.registered_carriers') ?: [];
    return $carriers[$current] ?? null;

}

/**
 * <USER>
 * Send an email.
 * @return bool Is it a success (true) or not (false)?
 */
function send_email(
    array | string $to,
    string         $subject,
    string         $body                 = '',
    ?string        $tpl                  = null,
    array          $vars                 = [],
    ?array         $from                 = null,
    array          $cc                   = [],
    array          $bcc                  = [],
    array          $reply                = [],
    ?bool          $debug                = null,
    bool           $debugShowAttachments = true,
    bool           $debugClose           = true,
    bool           $debugSend            = false,
    ?bool          $store                = null,
): bool
{
    if ($debug === null) $debug = cfg('~@emails.debug') ?: false;
    if ($store === null) $store = cfg('~@emails.store') ?: false;
    if ($from === null) if (!($from = cfg('~@emails.addresses.from'))) throw new Microbe_Exception("Trying to send an email without a valid From address");
    if (!$tpl && !$body) throw new Microbe_Exception("Trying to send an email without passing a body or a template name.");
    if (!$subject) throw new Microbe_Exception("Trying to send an email without a subject");

    // If the recipient is forced in the configuration, we set it here
    if (cfg('~@emails.addresses.to.enabled')
        && ($toAddr = cfg('~@emails.addresses.to.address'))
        && ($toName = cfg('~@emails.addresses.to.name'))) {
        $to = [ [ 'address' => $toAddr, 'name' => $toName ]];
    }

    // We prepare the addresses
    $from = [ $from ];
    if (is_string($to)) $to = [ $to ];
    foreach ([ 'to', 'from', 'cc', 'bcc', 'reply' ] as $k) {
        $v = [];
        foreach ($$k as $entry) {
            if (is_numeric_array($entry) && ($entry = array_values($entry))) $entry = (object) [ 'address' => $entry[0], 'name' => $entry[1] ?? '' ];
            else if (is_string($entry)) {
                if (preg_match('/^(?<name>.+)\<(?<addr>.+@.+)\>$/', $entry, $m)) $entry = (object) [ 'address' => $m['addr'], 'name' => trim($m['name']) ];
                else $entry = (object) [ 'address' => $entry, 'name' => '' ];
            }

            if (is_assoc_array($entry)) $entry = (object) $entry;
            if (!is_object($entry) || !property_exists($entry, 'address')) continue;
            if (!property_exists($entry, 'name')) $entry->name = '';
            $v[] = (object) $entry;
        }
        $$k = $v;
    }
    $from = (object) $from[0];

    // We create the HTML body
    if ($tpl) {
        $body = render(
            return: true,
            tpl:    $tpl,
            vars:   array_merge([
                'subject'     => $subject,
                'to'          => $to,
                'from'        => $from,
                'debug_email' => $debug,
            ], $vars),
        );
    }

    // We parse the HTML, checking the embedded images
    $content = process_embedded_email_body($body, $debug);

    if ($store && ($firstTo = ($to[0] ?? null))) {
        $dt = new DateTime();
        $storedPath = get_data_dir('emails',
            $dt->format('Ym'),
            $dt->format('YmdHis')
            . '-' . ((string) (int) round(microtime(true) * 1000000))
            . '-' . preg_replace('/[^A-Za-z0-9_.-]/', '', str_replace('@', '--at--', $firstTo->address))
            . '.html');
        rmkdir(dirname($storedPath));
        file_put_contents($storedPath, $content->body);
    }

    // If we are in debug mode, we show the result in the browser
    if ($debug) {
        header('Content-type: text/html; charset=utf-8');
        echo $content->body;

        if ($debugShowAttachments && $content->attachments) {
            $info = dom('div')
                ->css([ 'overflow' => 'hidden', 'background' => '#222', 'border-top' => '1px dashed #333', 'color' => '#eee', 'font-family' => 'monospace' ])
                ->append(dom('fieldset')
                    ->css([ 'margin' => '30px' ])
                    ->append(dom('legend')->append("Embedded images"))
                    ->append($ul = dom('ul')));
            foreach ($content->attachments as $cid => $path) {
                dom('li')
                    ->append(dom('strong')->append($cid))
                    ->append(' &rarr; ' . $path . ' (' . bytes_unit(filesize($path)) . ')')
                    ->appendTo($ul);
            }
            echo $info;
        }

        if ($debugClose) exit;
        return true;
    }

    if (!cfg('~@emails.carriers_registered')) {
        dispatch('register_email_carrier');
        cfg('@emails.carriers_registered', true);
    }

    // And send with the proper carrier.
    if (!($carrier = get_current_email_carrier())) {
        throw new Microbe_Exception("The current carrier is not defined in the configuration or the carrier is not registered.");
    }

    if (!cfg('~@emails.send')) return true;

    $opts = (object) [];
    foreach ([ 'debug', 'debugSend', 'to', 'from', 'reply', 'cc', 'bcc', 'subject', 'body', 'tpl', 'vars' ] as $k) $opts->$k = $$k;

    return call_user_func($carrier->func,
        to_object(cfg('~@emails.carriers')[$carrier->name] ?? []),
        $opts,
        $content->body,
        $content->attachments);
}

/**
 * Process the body string, to find the src="@cid{...}" or src="@base64{...}"
 * images and process it.
 * An object with the new body and the @cid attachments will be returned.
 * The @cid will be replaced by a src="cid:{sha1_of_the_path}", and this
 * identifier will be returned as a key of the returned attachments, with the
 * file path as a value.
 * The @base64 images will be replaced by an inline src, base64 encoded.
 * @param  string  $body  Body string.
 * @param  boolean $debug If the debug mode is enabled, the image will be kept
 *                        unchanged, to be able to see it in a browser display.
 * @return object         Object, containing the 'body' and the 'attachments'.
 */
function process_embedded_email_body(string $body, bool $debug = false): object
{
    $response = (object) [
        'body'        => $body,
        'attachments' => [],
    ];

    if (!preg_match_all('/@(cid|base64)\{([^}]+)\}/', $response->body, $matches, PREG_SET_ORDER)) {
        return $response;
    }

    foreach ($matches as $m) {
        if (!is_file($path = join_path(get_root_dir(), ltrim($m[2], DIRECTORY_SEPARATOR)))) {
            continue;
        }

        $replacement = $m[0];

        if ($m[1] === 'cid') {
            // If we are in debug mode, we still store the items to return them,
            // but we replace them as a base64 data to show them in the browser.
            if (!($cid = array_search($path, $response->attachments))) {
                $cid = sha1($path);
                $response->attachments[$cid] = $path;
            }
            if (!$debug) {
                $replacement = 'cid:' . $cid;
            }
        }

        // If it's embedded as a base64, or in debug mode we force this
        // replacement to show image in browser
        if ($m[1] === 'base64' || $debug) {
            list($w, $h, $type, $attr) = getimagesize($path);
            $mime = image_type_to_mime_type($type);
            $b64 = base64_encode(file_get_contents($path));
            $replacement = 'data:' . $mime . ';base64,' . $b64;
        }

        $response->body = str_replace($m[0], $replacement, $response->body);
    }

    return $response;
}

/**
 * <USER>
 * Returns an array of stored emails (years-months or HTML files).
 * @param  string $ym Year-Month. If not provided, available years-months
 *                    will be returned instead of HTML files.
 * @return array      Array of stored emails files.
 */
function get_stored_emails(?string $ym = null): array
{
    $dir = get_data_dir('emails');
    if (!is_dir($dir)) return [];
    if (!$ym) return get_folders($dir);
    return get_files(join_path($dir, $ym), filter: '/\.html$/');
}

// =============================================================================
// ---{ Listeners }-------------------------------------------------------------

listen('register_cfg_snippets', function(): array
{
    return [
        'emails' => [
            'debug'   => false,
            'method'  => 'smtp',
            'from'    => [ 'address' => null, 'name' => '{@app.name}' ],
            'contact' => [ 'address' => null, 'name' => '{@app.name}' ],
            'smtp'    => [
                'host'     => null,
                'user'     => null,
                'password' => null,
                'secure'   => 'tls',
                'port'     => 587,
            ],
        ],
    ];
});

listen('register_email_carrier', function(): void
{
    register_email_carrier(
        name: 'mailjet',
        func: function(object $cfg, object $opts, string $body, array $embeddedImages): bool
        {
            if (!($cfg->user ?? null)) throw new Microbe_Exception("Trying to send with Mailjet without any user");

            $msg = [
                'From'     => [ 'Email' => $opts->from->address, 'Name' => $opts->from->name ],
                'To'       => [],
                'Subject'  => $opts->subject,
                'HTMLPart' => $body,
            ];

            foreach ($opts->to as $entry) $msg['To'][] = [ 'Email' => $entry->address, 'Name' => $entry->name ];

            if ($opts->bcc)   foreach ($opts->bcc as $entry) $msg['BCC'][] = [ 'Email' => $entry->address, 'Name' => $entry->name ];
            if ($opts->reply) foreach ($opts->reply as $entry) $msg['ReplyTo'][] = [ 'Email' => $entry->address, 'Name' => $entry->name ];
            if ($opts->cc)    foreach ($opts->cc as $entry) $msg['CC'][] = [ 'Email' => $entry->address, 'Name' => $entry->name ];

            $response = curl(
                url:      'https://api.mailjet.com/v3.1/send',
                method:   'post',
                data:     json_encode([ 'Messages' => [ $msg ] ]),
                json:     true,
                headers:  [ 'Content-Type: application/json' ],
                username: $cfg->user,
                password: $cfg->password ?? null,
            );

            return true;
        },
    );

    register_email_carrier(
        name: 'mailgun',
        func: function(object $cfg, object $opts, string $body, array $embeddedImages): bool
        {
            $cfg = $cfg->sandbox->enabled ? $cfg->sandbox : $cfg->prod;

            $data = [
                'from'    => $opts->from->name . ' <' . $opts->from->address . '>',
                'subject' => $opts->subject,
                'html'    => $body,
            ];

            foreach ([ 'to', 'bcc', 'cc' ] as $field) {
                $data[$field] = [];
                foreach ($opts->$field as $entry) $data[$field][] = $entry->name . ' <' . $entry->address . '>';
                $data[$field] = implode(', ', $data[$field]);
            }

            $response = curl(
                url:      'https://' . $cfg->api_url . '/' . $cfg->domain . '/messages',
                method:   'post',
                data:     $data,
                json:     true,
                username: 'api',
                password: $cfg->api_key ?? null,
            );

            if (!$response || !($response->id ?? null)) throw new Microbe_Exception("Error while sending email through Mailgun API: " . print_r($response, true));
            return true;
        },
    );

    register_email_carrier(
        name: 'phpmailer',
        func: function(object $cfg, object $opts, string $body, array $embeddedImages): bool
        {
            if (!class_exists($className = 'Microbe_PHPMailer_PHPMailer')) throw new Microbe_Exception("PHPMailer for Microbe is not loaded");
            if (!class_exists($classNameSmtp = 'Microbe_PHPMailer_SMTP')) throw new Microbe_Exception("PHPMailer for Microbe is missing SMTP class");
            if (!($host = ($cfg->host ?? null))) throw new Microbe_Exception("Trying to send with SMTP without any host");

            $mail = new $className(true);

            if ($opts->debugSend) {
                $mail->SMTPDebug = 4;
                $mail->Debugoutput = 'echo';
            }

            $mail->CharSet = 'UTF-8';

            try {

                $mail->isSMTP();

                $mail->Host = $cfg->host;
                $mail->SMTPSecure = $cfg->secure ?: 'tls';
                $mail->Port = $cfg->port ?: 587;
                if ($mail->SMTPAuth = (bool) $cfg->user) {
                    $mail->Username = $cfg->user;
                    $mail->Password = $cfg->password;
                }

                $mail->setFrom($opts->from->address, $opts->from->name);

                foreach ($opts->to as $entry) $mail->addAddress($entry->address, $entry->name);
                foreach ($opts->bcc as $entry) $mail->addBCC($entry->address, $entry->name);
                foreach ($opts->reply as $entry) $mail->addReplyTo($entry->address, $entry->name);
                foreach ($opts->cc as $entry) $mail->addCC($entry->address, $entry->name);

                foreach ($embeddedImages as $cid => $path) $mail->AddEmbeddedImage($path, $cid);

                $mail->isHTML(true);
                $mail->Subject = '=?utf-8?B?' . base64_encode($opts->subject) . '?=';
                $mail->Body = $body;
                $mail->send();

                return true;

            } catch (Exception $e) {

                throw $e;

            }

            return false;
        },
    );

});

// =============================================================================
