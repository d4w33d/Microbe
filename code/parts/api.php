<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

function get_registered_api_collection(): array
{
    return stored('api') ?: [];
}

function get_registered_api(string $apiName): ?object
{
    return stored('api.' . $apiName);
}

function register_api(
    string   $route,
    string   $name          = 'default',
    ?string  $domain        = null,
    ?Closure $authenticator = null,
): void
{
    $collection = get_registered_api_collection();
    $collection[$name] = (object) [
        'route'         => $route,
        'name'          => $name,
        'domain'        => $domain,
        'authenticator' => $authenticator,
        'endpoints'     => [],
    ];
    stored('api', $collection);
}

function get_api_endpoints(string $apiName): array
{
    if (!($api = get_registered_api($apiName))) throw new Microbe_Exception("Invalid API name when trying to get endpoints");
    return $api->endpoints;
}

function api_endpoint(
    string  $path,
    Closure $action,
    bool    $authenticated = true,
    string  $method        = 'get',
    ?string $description   = null,
    string  $fieldsMode    = 'json_body',
    string  $fieldsVarName = 'data',
    array   $fields        = [],
    string  $apiName       = 'default',
): void
{
    $allowedHttpMethods = [ 'GET', 'HEAD', 'POST', 'PUT', 'DELETE', 'CONNECT', 'OPTIONS', 'TRACE', 'PATCH' ];
    $allowedFieldsModes = [ 'post_vars', 'json_body', 'json_var' ];

    $method = strtoupper($method);
    if (!in_array($method, $allowedHttpMethods)) throw new Microbe_Exception("Trying to define an API Endpoint with an invalid HTTP method");

    $collection = get_registered_api_collection();
    if (!array_key_exists($apiName, $collection)) throw new Microbe_Exception("Trying to define an API Endpoint on an undefined API");
    if (!array_key_exists($path, $collection[$apiName]->endpoints)) $collection[$apiName]->endpoints[$path] = [];
    if (!in_array($fieldsMode, $allowedFieldsModes)) throw new Microbe_Exception("Trying to define an API Endpoint with an invalid Fields Mode. Should be one of these: " . implode(', ', $allowedFieldsModes));

    $collection[$apiName]->endpoints[$path][$method] = (object) [
        'api_name'        => $apiName,
        'path'            => $path,
        'method'          => $method,
        'action'          => $action,
        'authenticated'   => $authenticated,
        'description'     => $description,
        'fields_mode'     => $fieldsMode,
        'fields_var_name' => $fieldsVarName,
        'fields'          => $fields,
    ];
    stored('api', $collection);
}

function declare_api_routes(): void
{
    $prettyJSON = (bool) ((int) ($_SERVER['HTTP_X_JSON_PRETTY'] ?? '0'));

    foreach (get_registered_api_collection() as $api) {
        clear_route_filters();
        if ($api->domain) register_domain_route_filter($api->domain);

        foreach ($api->endpoints as $endpointPath => $endpointMethods) {
            route($api->route . '/' . $endpointPath, function(?string... $args) use ($api, $endpointPath, $endpointMethods, $prettyJSON): void
            {
                // Get endpoint for current HTTP method
                $httpMethod = get_http_method();
                if (!($endpoint = $endpointMethods[$httpMethod] ?? null)) json_error("Invalid Endpoint Method", code: 404, pretty: $prettyJSON);

                // Authenticate, and deny if mandatory but not authenticated
                list($user, $token) = call_user_func($api->authenticator);
                if ($endpoint->authenticated && (!$user && !$token)) json_error("Unauthorized", code: 403, pretty: $prettyJSON);

                // Parse endpoint arguments from path
                $arguments = [];
                if (preg_match_all('/<([^>]+)>/', $endpoint->path, $matches, PREG_SET_ORDER)) {
                    foreach ($matches as $idx => $m) {
                        $arguments[$m[1]] = $args[$idx] ?? null;
                    }
                }

                // Get proper body data
                $bodyRequestsMethods = [ 'POST', 'PUT' ];
                $isBodyRequest = in_array($httpMethod, $bodyRequestsMethods);
                $postedBodyData = null;
                if ($isBodyRequest) {
                    if ($endpoint->fields_mode === 'json_body') {
                        if (($postedBody = trim(file_get_contents('php://input') ?: ''))) $postedBodyData = json_decode($postedBody, true) ?? [];
                    } else if ($endpoint->fields_mode === 'json_var') {
                        if (($postedVarData = get_posted($endpoint->fields_var_name)) && is_string($postedVarData)) $postedBodyData = json_decode($postedVarData, true) ?? [];
                    } else if ($endpoint->fields_mode === 'post_vars') {
                        $postedBodyData = $_POST;
                    }
                }

                // Parse get/post fields
                $fields = [];
                foreach ($endpoint->fields as $fieldName => $o) {
                    $o = (object) array_merge([
                        'required'    => false,
                        'type'        => 'string',
                        'multiple'    => false,
                        'signed'      => true,
                        'default'     => null,
                        'description' => null,
                    ], $o);

                    $values = $isBodyRequest ? ($postedBodyData[$fieldName] ?? null) : get_multiple($fieldName);
                    if (!is_numeric_array($values)) $values = [ $values ];
                    $values = array_values(array_filter(array_map(function(mixed $value) use ($o): mixed
                    {
                        if (!is_scalar($value)) return null;
                        return match($o->type) {
                            'float'    => is_numeric($value) ? ($o->signed ? (float) $value : abs((float) $value)) : null,
                            'int'      => is_numeric($value) ? ($o->signed ? (int) $value : abs((int) $value)) : null,
                            'bool'     => $value === true || $value === 1 || $value === '1',
                            'datetime' => preg_match('/^\d{4}(-\d{2}){2}[ T]\d{2}(:\d{2}){2}(\.\d+)?(\+\d{2}:\d{2})?$/', $value) ? new DateTime($value) : null,
                            'date'     => preg_match('/^\d{4}(-\d{2}){2}(\+\d{2}:\d{2})?$/', $value) ? (new DateTime($value))->setTime(0, 0, 0) : null,
                            'time'     => preg_match('/^(?<h>\d{2}):(?<i>\d{2}):(?<s>\d{2}(\.\d+)?)$/', $value, $m) ? (((int) $m['h']) * 3600) + (((int) $m['i']) * 60) + ((float) $m['s']) : null,
                            'string'   => preg_replace("/[\r\n]/", '', (string) $value),
                            'text'     => (string) $value,
                        };
                    }, $values)));

                    if ($o->required && (!count($values) || (!$o->multiple && !$values[0]))) json_error("Missing Mandatory Field", [ 'field' => $fieldName ], code: 500, pretty: $prettyJSON);
                    $value = $o->multiple ? $values : ($values[0] ?? null);
                    $fields[$fieldName] = $value;
                }

                // Call action function
                $response = call_user_func($endpoint->action,
                    (object) $arguments,
                    $fields,
                    $user,
                    $token);

                // Return response as JSON
                if ($response === false || is_string($response)) json_error($response ?: "Internal Error", code: 500, pretty: $prettyJSON);
                json_success($response === true ? [] : $response, pretty: $prettyJSON);
            });
        }

        route($api->route . '/<*nothing>', function(string $nothing) use ($prettyJSON): void
        {
            json_error("Invalid Endpoint", code: 404, pretty: $prettyJSON);
        });

        if ($api->domain) clear_route_filters();
    }
}

// =============================================================================
