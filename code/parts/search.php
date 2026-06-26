<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Convert user's conditional search terms to SQL query.
 * @param  string $terms  Search terms.
 * @param  array  $fields Whitelist fields.
 *                          - The key is the field allowed in $terms.
 *                          - The value is the MySQL column name.
 * @return object         An object whith the following properties:
 *                          - sql
 *                          - args
 */
function process_search_terms_to_sql(string $terms, array $fields): object
{
    $replacements = [
        '/is\s+equal\s+to/i'             => '=',
        '/is\s+not\s+equal\s+to/i'       => '!=',
        '/is\s+not/i'                    => '!=',
        '/less\s+or\s+equal\s+than/i'    => '<=',
        '/less\s+than/i'                 => '<',
        '/greater\s+or\s+equal\s+than/i' => '>=',
        '/more\s+or\s+equal\s+than/i'    => '>=',
        '/greater\s+than/i'              => '>',
        '/more\s+than/i'                 => '>',
    ];

    $terms = preg_replace(array_keys($replacements), array_values($replacements), $terms);
    $tokens = tokenize_search_terms($terms);
    $parser = new Microbe_Search_Parser($tokens, $fields);
    return $parser->parse();
}

/**
 * Tokenize some user's search terms.
 * @param  string $terms Search terms.
 * @return array         Search tokens.
 */
function tokenize_search_terms(string $terms): array
{
    $pattern = '/\s*(\s+|>=|<=|!=|<>|=|\(|\)|[\'"][^\'"]*[\'"]|[^\s()=!<>]+)\s*/';
    preg_match_all($pattern, $terms, $matches);
    $tokens = [];
    foreach ($matches[1] as $token) {
        $token = trim($token);
        if ($token === '') continue;
        if (($token[0] === "'" && substr($token, -1) === "'") || ($token[0] === '"' && substr($token, -1) === '"')) {
            $tokens[] = ['type' => 'VALUE', 'value' => substr($token, 1, -1)];
        } else {
            $tokens[] = get_search_terms_token_type($token);
        }
    }
    return $tokens;
}

/**
 * Returns token type based on term part.
 * @param  string $token Term part as a token.
 * @return array         Associative array describing the type and the value
 *                       of the token.
 */
function get_search_terms_token_type(string $token): array
{
    $upper = strtoupper($token);
    return match ($upper) {
        'AND'                  => [ 'type' => 'LOGIC',      'value' => 'AND'  ],
        'OR'                   => [ 'type' => 'LOGIC',      'value' => 'OR'   ],
        '('                    => [ 'type' => 'LPAREN',     'value' => '('    ],
        ')'                    => [ 'type' => 'RPAREN',     'value' => ')'    ],
        '=', 'EQUALS', 'IS'    => [ 'type' => 'OPERATOR',   'value' => '='    ],
        '!=', '<>'             => [ 'type' => 'OPERATOR',   'value' => '!='   ],
        '<', 'LESS'            => [ 'type' => 'OPERATOR',   'value' => '<'    ],
        '<='                   => [ 'type' => 'OPERATOR',   'value' => '<='   ],
        '>', 'GREATER', 'MORE' => [ 'type' => 'OPERATOR',   'value' => '>'    ],
        '>='                   => [ 'type' => 'OPERATOR',   'value' => '>='   ],
        'CONTAINS'             => [ 'type' => 'OPERATOR',   'value' => 'LIKE' ],
        'IN'                   => [ 'type' => 'OPERATOR',   'value' => 'IN'   ],
        'TRUE'                 => [ 'type' => 'BOOLEAN',    'value' => true   ],
        'FALSE'                => [ 'type' => 'BOOLEAN',    'value' => false  ],
        default                => [ 'type' => 'IDENTIFIER', 'value' => $token ],
    };
}

// =============================================================================
// ---{ Classes }---------------------------------------------------------------

// ---{ Class: Microbe Search Parser }---

class Microbe_Search_Parser
{

    private array  $tokens;
    private array  $fields;
    private string $sqlParamPrefix;
    private int    $index      = 0;
    private array  $args       = [];
    private int    $argCounter = 0;

    public function __construct(array $tokens, array $fields, string $sqlParamPrefix = 'search_param_')
    {
        $this->tokens = $tokens;
        $this->fields = $fields;
        $this->sqlParamPrefix = $sqlParamPrefix;
    }

    public function parse(): object
    {
        if (!$this->tokens) return (object) [ 'sql' => '', 'args' => [] ];
        if (!$this->isConditionalQuery()) return $this->generateFullTextFallback();
        $sql = $this->parseExpression();
        return (object) [
            'sql'  => $sql,
            'args' => $this->args,
        ];
    }

    private function isConditionalQuery(): bool
    {
        foreach ($this->tokens as $t) {
            if (in_array($t['type'], [ 'OPERATOR', 'LPAREN', 'LOGIC' ])) {
                return true;
            }
        }
        return false;
    }

    private function generateFullTextFallback(): object
    {
        $clauses = [];
        foreach ($this->tokens as $token) {
            if ($token['type'] !== 'IDENTIFIER' && $token['type'] !== 'VALUE') continue;
            foreach ($this->fields as $dbField) {
                $paramName = $this->sqlParamPrefix . ++$this->argCounter;
                $clauses[] = "$dbField LIKE :$paramName";
                $this->args[$paramName] = '%' . $token['value'] . '%';
            }
        }
        return (object) [
            'sql'  => $clauses ? '(' . implode(' OR ', $clauses) . ')' : '1 = 1',
            'args' => $this->args
        ];
    }

    private function parseExpression(): string
    {
        $left = $this->parseTerm();
        while ($this->index < count($this->tokens) && $this->tokens[$this->index]['type'] === 'LOGIC') {
            $logic = $this->tokens[$this->index]['value'];
            $this->index++;
            $right = $this->parseTerm();
            $left = "($left $logic $right)";
        }
        return $left;
    }

    private function parseTerm(): string
    {
        $token = $this->tokens[$this->index] ?? null;
        if ($token && $token['type'] === 'LPAREN') {
            $this->index++; // Burn '('
            $expr = $this->parseExpression();
            $this->index++; // Burn ')'
            return "($expr)";
        }
        return $this->parseCondition();
    }

    private function parseCondition(): string
    {
        $fieldToken = $this->tokens[$this->index++] ?? null;

        // If the field is not in whitelist, skip it.
        if (!$fieldToken || !array_key_exists($fieldToken['value'], $this->fields)) return '1 = 1';

        $dbField = $this->fields[$fieldToken['value']];
        $opToken = $this->tokens[$this->index++] ?? null;

        if (!$opToken || $opToken['type'] !== 'OPERATOR') return '1 = 1';

        $operator = $opToken['value'];

        if ($operator === 'IN') return $this->handleInOperator($dbField);

        $valToken = $this->tokens[$this->index++] ?? null;
        if (!$valToken) return '1 = 1';

        $paramName = $this->sqlParamPrefix . ++$this->argCounter;

        if ($operator === 'LIKE') {
            $this->args[$paramName] = '%' . $valToken['value'] . '%';
            return "$dbField LIKE :$paramName";
        }

        $this->args[$paramName] = $valToken['value'];
        return "$dbField $operator :$paramName";
    }

    private function handleInOperator(string $dbField): string
    {
        if (($this->tokens[$this->index]['type'] ?? null) === 'LPAREN') $this->index++; // Burn '('

        $params = [];
        while ($this->index < count($this->tokens) && $this->tokens[$this->index]['type'] !== 'RPAREN') {
            $token = $this->tokens[$this->index++];
            if (in_array($token['type'], [ 'VALUE', 'IDENTIFIER', 'BOOLEAN' ])) {
                $paramName = $this->sqlParamPrefix . ++$this->argCounter;
                $this->args[$paramName] = $token['value'];
                $params[] = ":$paramName";
            }
        }

        if (($this->tokens[$this->index]['type'] ?? null) === 'RPAREN') $this->index++; // Burn ')'

        return $params ? "$dbField IN (" . implode(', ', $params) . ")" : '1 = 0';
    }

}
