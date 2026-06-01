<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

function cli_run(): void
{
    $argv = $_SERVER['argv'];
    array_shift($argv);

    $bundleName = defined('MB_CLI_BUNDLE') ? MB_CLI_BUNDLE : ($argv ? array_shift($argv) : null);
    if (!$bundleName) {
        cli_actions_man();
        cli_error("Missing a bundle: 'core' or 'bundle:{bundle_name}'.");
    }

    $bundleName = $bundleName === 'core' ? $bundleName : (preg_match('/^bundle:(?<bundle>[a-z0-9_.-]+)$/', $bundleName, $m) ? $m['bundle'] : null);

    if (!$bundleName) {
        cli_actions_man();
        cli_error("Invalid bundle: should be 'core' or 'bundle:{bundle_name}'.");
    }

    if (!($actionName = ($argv ? array_shift($argv) : null))) {
        cli_actions_man(bundleName: $bundleName);
        cli_error("Missing action name.");
    }

    if (!($action = get_cli_action(bundle: $bundleName, name: $actionName))) {
        cli_actions_man(bundleName: $bundleName);
        cli_error("Unknown action '{$actionName}'.");
    }

    $args = $argv;
    array_unshift($args, cli_opts($action->opts));
    call_user_func_array($action->func, $args);
}

function cli_opts(array $opts, null | string | array $str = null): object
{
    if ($str === null) $str = $_SERVER['argv'];
    if (is_array($str)) $str = implode(' ', $str);

    $replacements = [];
    if (preg_match_all('/="([^"]+)"/', $str, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $replacements[$k = '____oO°_' . count($replacements) . '_°Oo____'] = $m[1];
            $str = preg_replace($m[0], '=' . $k, $str, 1);
        }
    }

    $str = ' ' . $str . ' ';
    $values = [];
    foreach ($opts as $oName => $oKeys) {
        $value = false;
        foreach ($oKeys as $k) {
            if ($k === '.') $k = $oName;
            if (strlen($k) === 1) {
                if (str_contains($str, " -{$k} ")) $value = true;
            } else if (str_contains($str, " --{$k} ")) {
                $value = true;
            } else if (preg_match('/ --' . preg_quote($k) . '=([^ ]*) /', $str, $m)) {
                $value = $replacements[$m[1]] ?? $m[1];
            }
        }
        $values[$oName] = $value;
    }
    return (object) $values;
}

function cli_size(): object
{
    if ($size = stored('cli.size')) return $size;
    stored('cli.size', $size = (object) [
        'width'  => (int) exec('tput cols'),
        'height' => (int) exec('tput lines'),
    ]);
    return $size;
}

function cli_width(): int
{
    return cli_size()->width;
}

function cli_height(): int
{
    return cli_size()->height;
}

function cli_write(string | array $str = '', bool | string | null $end = true): void
{
    if ($end === null) $end = false;
    if ($end === true) $end = "\n";
    if (!is_array($str)) $str = [ $str ];
    $str = implode("\n", $str);
    $str = str_replace('|_ ', mb_chr(9492) . ' ', $str);
    $str = str_replace('|- ', mb_chr(9500) . ' ', $str);
    echo $str;
    if ($end) echo $end;
}

function cli_prompt(?string $str = null): string
{
    if ($str) cli_write($str, end: false);
    $handle = fopen('php://stdin', 'r');
    $value = fgets($handle);
    fclose($handle);
    return trim($value);
}

function cli_error(string | array $str): void
{
    cli_write("[ERROR] " . $str);
    cli_write();
    exit;
}

function cli_strip_colors(string $str): string
{
    return preg_replace('/\e\[[0-9;]*[a-zA-Z]/', '', $str);
}

function cli_table(array $rows, ?string $heading = null): void
{
    $cols = null;
    foreach ($rows as $row) {
        if ($cols === null) $cols = array_fill_keys(array_keys($row), 0);
        foreach ($row as $k => $v) $cols[$k] = max($cols[$k], strlen($k), strlen($v));
    }

    $line = [];
    foreach ($cols as $col => $size) $line[] = str_replace('-', '─', str_pad('', $size, '-', STR_PAD_RIGHT));

    $lines = (object) [
        'simple'   => implode('---', array_map(function(string $s): string { return str_replace('─', '-', $s); }, $line)),
        'head_sup' => '┌─' . implode('───', $line) . '─┐',
        'head_sub' => '├─' . implode('─┬─', $line) . '─┤',
        'body_sup' => '┌─' . implode('─┬─', $line) . '─┐',
        'body_mid' => '├─' . implode('─┼─', $line) . '─┤',
        'body_sub' => '└─' . implode('─┴─', $line) . '─┘',
    ];

    $s = [];

    if ($heading) {
        $s[] = $lines->head_sup;
        $s[] = '│ ' . str_pad($heading, strlen($lines->simple), ' ', STR_PAD_BOTH) . ' │';
        $s[] = $lines->head_sub;
    } else {
        $s[] = $lines->body_sup;
    }

    $head = [];
    foreach ($cols as $col => $size) $head[] = str_pad(ucfirst($col), $size, ' ', STR_PAD_RIGHT);
    $s[] = '│ ' . implode(' │ ', $head) . ' │';

    $s[] = $lines->body_mid;

    foreach ($rows as $row) {
        $r = [];
        foreach ($cols as $col => $size) $r[] = str_pad($row[$col], $size, ' ', STR_PAD_RIGHT);
        $s[] = '│ ' . implode(' │ ', $r) . ' │';
    }

    $s[] = $lines->body_sub;

    cli_write();
    echo implode("\n", $s) . "\n\n";
}

// ---{ Actions }---------------------------------------------------------------

function get_cli_actions(): array
{
    return cfg('~@core.cli.actions') ?: [];
}

function get_cli_action(string $bundle, string $name): ?object
{
    return get_cli_actions()["{$bundle}.{$name}"] ?? null;
}

function register_cli_action(string $bundle, string $name, Closure $func, array $opts = [], ?string $description = null): void
{
    $actions = get_cli_actions();
    $actions["{$bundle}.{$name}"] = (object) [
        'bundle'      => $bundle,
        'name'        => $name,
        'description' => $description,
        'func'        => $func,
        'opts'        => $opts,
    ];
    cfg('@core.cli.actions', $actions);
}

function cli_actions_man(?string $bundleName = null): void
{
    cli_table(
        heading: "Available CLI Actions",
        rows: array_values(array_filter(array_map(function(object $a) use ($bundleName): ?array
        {
            $cols = [];
            if ($bundleName !== null) {
                if ($a->bundle !== $bundleName) return null;
            } else {
                $cols['bundle'] = $a->bundle;
            }
            $cols['name'] = $a->name;
            $cols['description'] = $a->description;
            return $cols;
        }, get_cli_actions()))),
    );
}

// =============================================================================
