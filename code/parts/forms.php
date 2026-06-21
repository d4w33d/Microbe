<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Validate some data got from $_POST or $_GET.
 * The fields are defined by an array containing an array per field, as:
 * [ 'name_of_the_field', '@type_of_field', '/opt1', '/opt2', fn() => ... ]
 * @param  array                            $fields   An array containing
 *                                                    information about fields.
 * @param  array|null                       $input    An array of data. If null,
 *                                                    it will be filled
 *                                                    following $method.
 * @param  string                           $method   'both', 'post' or 'get'
 * @param  array|null                       $messages Associative array of
 *                                                    error codes/messages.
 * @return Microbe_Data_Validation_Response           Response instance.
 */
function validate_data(
    array  $fields,
    ?array $input    = null,
    string $method   = 'both',
    ?array $messages = [],
): Microbe_Data_Validation_Response
{
    $messages = array_merge([
        'required'          => t("Value Is Required"),
        'below_min_value'   => t("Value Is Lower Than Minimum Value Allowed"),
        'over_max_value'    => t("Value Is Higher Than Maximum Value Allowed"),
        'invalid_hex_color' => t("Value Is Not A Valid Hexadecimal Color"),
    ], $messages);

    if ($input === null) {
        $input = [];
        if ($method === 'both' || $method === 'post') $input = array_merge($input, $_POST);
        if ($method === 'both' || $method === 'get') $input = array_merge($input, $_GET);
    }

    $response = new Microbe_Data_Validation_Response();

    $protectedOpts = array_fill_keys([ 'name', 'type', 'value', 'error' ], true);

    foreach ($fields as $cols) {
        $o = (object) [

            'name'     => array_shift($cols),
            'type'     => trim(array_shift($cols), '@'),
            'value'    => null,
            'error'    => (object) [ 'code' => null, 'message' => null ],

            'trim'     => false,
            'safe'     => false,
            'req'      => false,
            'required' => false,
            'null'     => false,
            'min'      => null,
            'max'      => null,
            'fn'       => [],

        ];

        if (!is_str_safe($o->name)) throw new Microbe_Exception("Invalid Field Name");
        if (!preg_match('/^[a-z0-9-]+$/', $o->type)) throw new Microbe_Exception("Invalid Field Type Format");

        foreach ($cols as $col) {
            if ($col instanceof Closure) $o->fn[] = $col;
            if (is_string($col)) {
                if (!preg_match('/^\/(?<opt>[a-z0-9-]+)(:(?<arg>.+))?$/', $col, $m)) throw new Microbe_Exception("Unable To Parse Field Option: {$col}");
                if (!property_exists($o, $m['opt'])) throw new Microbe_Exception("Invalid Field Option: {$m['opt']}");
                if (isset($protectedOpts[$m['opt']])) throw new Microbe_Exception("Protected Field Option: {$m['opt']}");
                $o->{$m['opt']} = is_bool($o->{$m['opt']}) ? true : ($m['arg'] ?? '');
                if (in_array($m['opt'], [ 'min', 'max' ])) $o->{$m['opt']} = (float) $o->{$m['opt']};
            }
        }

        if ($o->required) $o->req = true;
        unset($o->required);

        [ $o->value, $o->error ] = process_data_value($o, $input[$o->name] ?? null);

        if ($o->error && !$o->error->hasMessage()) {
            $o->error->setMessage($messages[$o->error->getCode() . '.' . $o->name]
                ?? $messages[$o->error->getCode()]
                ?? ucwords(preg_replace('/[_.-]/', ' ', strtolower($o->error->getCode()))));
        }

        $response->setField($o);
    }

    return $response;
}

/**
 * Process specific data value.
 * @param  object $opts  Field information.
 * @param  mixed  $value Value got from input data.
 * @return array         A two-entries array:
 *                       [ mixed $value, ?Microbe_Data_Validation_Response $r ]
 */
function process_data_value(object $opts, mixed $value): array
{
    if (in_array($opts->type, [ 'str', 'txt', 'uid' ])) {

        $value = is_scalar($value) ? (string) $value : '';

        if ($opts->type === 'str') $value = str_replace([ "\n", "\r", "\t" ], '', $value);
        else if ($opts->type === 'uid') $value = preg_replace('/[^a-z0-9]/i', '', $value);

        if ($opts->safe) $value = make_str_safe($value);
        if ($opts->trim) $value = trim($value);

        if ($opts->null && $value === '') $value = null;

    } else if (in_array($opts->type, [ 'int', 'float' ])) {

        $value = is_scalar($value) && preg_match('/^[+-]([0-9]+|[0-9]*\.[0-9]+)$/', $value = (string) $value) ? (float) $value : null;

        if ($value !== null) {
            if ($opts->type === 'int') $value = (int) $value;
            if ($opts->min !== null && $value < $opts->min) return [ $value, data_error('below_min_value') ];
            if ($opts->max !== null && $value > $opts->max) return [ $value, data_error('over_max_value') ];
        }

    } else if ($opts->type === 'color-hex' || $opts->type === 'color-hex-alpha') {

        $value = is_scalar($value) ? strtolower(trim((string) $value, "# \t\n\r\0\v")) : null;
        if ($value !== null) {
            if (!preg_match('/^((?<three>[0-9a-f]{3})|[0-9a-f]{6}|[0-9a-f]{8})$/', $value, $m)) return [ $value, data_error('invalid_hex_color') ];
            $len = strlen($value);
            if ($m['three'] ?? null) $value = '#' . $value[0] . $value[0] . $value[1] . $value[1] . $value[2] . $value[2];
            else if ($opts->type === 'color-hex' && $len !== 6) return [ '#' . $value, data_error('hex_color_with_unexpected_alpha') ];
            else if ($opts->type === 'color-hex-alpha' && $len !== 8) $value = '#' . $value . 'ff';
            else $value = '#' . $value;
        }

    } else throw new Microbe_Exception("Unhandled Data Type: {$opts->type}");

    if ($value === null || $value === '') return [ $value, $opts->req ? data_error('required') : null ];

    foreach ($opts->fn as $fn) {
        $v = $fn($value);
        if ($v instanceof Microbe_Data_Validation_Error) return [ $value, $v ];
        $value = $v;
    }

    return [ $value, null ];
}

/**
 * <USER>
 * Shortcut to generate a form field error when validating data
 * using <validate_data()>.
 * @param  string|null                   $code    Error code.
 * @param  string|null                   $message Error message.
 * @return Microbe_Data_Validation_Error          Error instance.
 */
function data_error(?string $code = null, ?string $message = null): Microbe_Data_Validation_Error
{
    return new Microbe_Data_Validation_Error($code, $message);
}

/**
 * <USER>
 * Validate some GET/POST parameters based on the rules given in the array,
 * then return the casted data and the errors if exists.
 * @param  array  $fields       Numerical array containing each field and its
 *                              rules, as a Closure getting the value as first
 *                              parameter, and returning an error as a string
 *                              or nothing if the rule passed. A special '@'
 *                              key can be included, to cast the value.
 *                              In this case, the return of the Closure will
 *                              be the casted value.
 * @param  boolean $dataAsArray Returns the data as an array instead
 *                              of an object.
 * @return array                An array containing two elements: 0 is an object
 *                              containing the casted data, and 1 is an array
 *                              containing the errors.
 */
function validate(array $fields, bool $dataAsArray = false): array
{
    $data = [];
    $errors = [];

    foreach ($fields as $name => $rules) {
        $value = get($name);
        foreach ($rules as $idx => $func) {
            if (in_array($idx, [ '@', 'cast' ])) {
                     if ($func === 'trim')         $value = $value === null ? null : (trim(is_scalar($value) ? ($value ?: '') : '') ?: '');
                else if ($func === 'int')          $value = is_int_val($value) ? (int) $value : null;
                else if ($func === 'float')        $value = is_float_val($value) ? (float) $value : null;
                else if ($func === 'bool')         $value = value_seems_true($value);
                else if ($func instanceof Closure) $value = $func($value);
                continue;
            }
            if (is_string($func) && str_starts_with($func, '?') && $value === '') $value = null;
            if (is_string($err = $func($value))) {
                $errors[$name] = $err;
                break;
            }
        }
        $data[$name] = $value;
    }

    if (!$dataAsArray) $data = (object) $data;
    return [ $data, $errors ];
}

/**
 * <USER>
 * Returns a new Microbe_Form instance.
 * @param  string|null  $name Name of the form (useful for storing results).
 * @return Microbe_Form       Form instance.
 */
function form(?string $name = null): Microbe_Form
{
    $form = new Microbe_Form();
    $form->setName($name);
    return $form;
}

/**
 * <USER>
 * Returns a new Microbe_Form_Field instance with a specific type.
 * @param  string                    $type   One of Microbe_Form_Field::T_*.
 * @param  Microbe_Form_Element|null $parent Parent as a Microbe_Form_Element
 *                                           instance.
 * @return Microbe_Form_Field                Form field instance.
 */
function form_field(string $type, ?Microbe_Form_Element $parent = null): Microbe_Form_Field
{
    return (new Microbe_Form_Field($type))->setParent($parent);
}

/**
 * <USER>
 * Get a form defined in a form file (located in the forms folder of
 * each bundle). The name of the form is the name of the file.
 * The file has to return a Microbe_Form instance, and receive optional
 * variables given by $args.
 * @param  string       $name Name of form file.
 * @param  array        $args Args passed to file before including it.
 * @return Microbe_Form       Form instance if found.
 */
function get_form(string $name, array $args = []): Microbe_Form
{
    $formName = $name;
    $bundleName = 'default';
    if (preg_match('/^@(?<bundleName>[_.a-z0-9]+)\/(?<formName>[_a-z0-9]+)$/i', $name, $m)) {
        $bundleName = $m['bundleName'];
        $formName = $m['formName'];
    }

    if (!($bundle = get_bundle($bundleName))) throw new Microbe_Exception("Invalid bundle name while trying to get one of its forms: {$name}");
    if (!is_file($path = join_path($bundle->dir, 'forms', $formName . '.php'))) throw new Microbe_Exception("Invalid form name: {$name}.");

    extract($args);
    if (!($form = (include $path))) throw new Microbe_Exception("Nothing was returned by the form file: {$name}. A Microbe_Form must be returned.");
    if (!($form instanceof Microbe_Form)) throw new Microbe_Exception("A Microbe_Form must be returned by the form file: {$name}.");
    if (!$form->getName()) $form->setName($name);
    return $form;
}

// =============================================================================
// ---{ Classes }---------------------------------------------------------------

// ---{ Class: Microbe Form Element }---

class Microbe_Form_Element
{

    protected ?Microbe_Form_Element $parent = null;
    protected array $children = [];
    private ?string $label = null;
    protected ?string $description = null;
    protected ?string $icon = null;
    protected ?string $iconFormat = null;

    protected array $errorMessages = [];

    public function __construct()
    {
        $this->errorMessages = [
            'mandatory'        => t("Field is mandatory"),
            'invalid_regexp'   => t("Invalid format"),
            'invalid_array'    => t("Invalid set of values"),
            'invalid_scalar'   => t("Invalid value"),
            'invalid_number'   => t("Invalid number"),
            'below_min'        => t("Value is below the minimum allowed"),
            'above_max'        => t("Value is above the maximum allowed"),
            'invalid_date'     => t("Invalid date"),
            'invalid_time'     => t("Invalid time"),
            'invalid_email'    => t("Invalid email address"),
            'weak_password'    => t("Password too weak"),
            'invalid_phone'    => t("Invalid phone number"),
            'invalid_url'      => t("Invalid URL format"),
            'below_min_length' => t("Length is too short"),
            'above_max_length' => t("Length is too long"),

        ];
    }

    public function __toString(): string
    {
        return $this->render(asString: true);
    }

    public function setLabel(?string $label): static
    {
        $this->label = $label;
        return $this;
    }

    public function getLabel(): ?string
    {
        return $this->label;
    }

    public function setDescription(?string $description = null): static
    {
        $this->description = $description;
        return $this;
    }

    public function getDescription(): ?string
    {
        return $this->description;
    }

    public function setIcon(?string $icon = null): static
    {
        $this->icon = $icon;
        return $this;
    }

    public function getIcon(): ?string
    {
        return $this->icon;
    }

    public function setParent(?Microbe_Form_Element $parent = null): static
    {
        $this->parent = $parent;
        return $this;
    }

    public function getParent(): ?Microbe_Form_Element
    {
        return $this->parent;
    }

    public function getForm(): ?Microbe_Form
    {
        if ($this instanceof Microbe_Form) return $this;
        $elem = $this;
        while ($elem = $elem->getParent()) {
            if ($elem instanceof Microbe_Form) return $elem;
        }
        return null;
    }

    public function addChild(Microbe_Form_Element $child): static
    {
        $this->children[] = $child;
        return $this;
    }

    public function getChildren(): array
    {
        return $this->children;
    }

    public function addGroup(): Microbe_Form_Group
    {
        $group = (new Microbe_Form_Group())
            ->setParent($this);
        $this->addChild($group);
        return $group;
    }

    public function addHtml(string $html): static
    {
        $html = (new Microbe_Form_Html())
            ->setParent($this)
            ->setHtml($html);
        return $this->addChild($html);
    }

    public function addField(Microbe_Form_Field | string $field): Microbe_Form_Field
    {
        if (!$this->children || !($this->children[array_key_last($this->children)] instanceof Microbe_Form_Group)) $this->addGroup();
        $group = $this->children[array_key_last($this->children)];
        $group->addField($field);
        return $this;
    }

    public function getFields(bool $recursive = false): array
    {
        $fields = [];
        foreach ($this->getChildren() as $child) {
            if ($child instanceof Microbe_Form_Field) $fields[] = $child;
            if ($recursive) $fields = array_merge($fields, $child->getFields());
        }
        return $fields;
    }

    public function setIconFormat(?string $iconFormat = null): static
    {
        $this->iconFormat = $iconFormat;
        return $this;
    }

    public function getIconFormat(): ?string
    {
        return $this->iconFormat;
    }

    public function formatIcon(string $icon): string
    {
        $iconFormat = null;
        $elem = $this;
        while ($elem) {
            if ($iconFormat = $elem->getIconFormat()) break;
            $elem = $elem->getParent();
        }
        return icon($icon, format: $iconFormat);
    }

    public function setErrorMessage(string $errorCode, ?string $errorMessage = null): static
    {
        if ($errorMessage === null) {
            if (array_key_exists($errorCode, $this->errorMessages)) unset($this->errorMessages[$errorCode]);
        } else {
            $this->errorMessages[$errorCode] = $errorMessage;
        }
        return $this;
    }

    public function getErrorMessage(string $errorCode, array $params = []): ?string
    {
        if (!($msg = ($this->errorMessages[$errorCode] ?? null))) return null;
        return replace_params($msg, $params);
    }

    public function render(bool $asString = false): string | Microbe_DOM_Element
    {
        $div = dom('div');
        return $asString ? (string) $div : $div;
    }

}

// ---{ Class: Microbe Form }---

class Microbe_Form extends Microbe_Form_Element
{

    private ?string                            $id           = null;
    private ?string                            $name         = null;
    private ?string                            $url          = null;
    private string                             $method       = 'get';
    private bool                               $multipart    = false;
    private array                              $buttons      = [];
    private ?string                            $header       = null;
    private ?string                            $footer       = null;
    private ?Microbe_Form_Result               $lastResult   = null;
    private Microbe_Form_Result | null | false $storedResult = false;
    private string | null | false              $storedError = false;

    public function setName(?string $name): static
    {
        $this->name = $name;
        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
    }

    public function setId(?string $id): static
    {
        $this->id = $id;
        return $this;
    }

    public function getId(): ?string
    {
        return $this->id;
    }

    public function setUrl(?string $url): static
    {
        $this->url = $url;
        return $this;
    }

    public function getUrl(): ?string
    {
        return $this->url;
    }

    public function setMethod(string $method): static
    {
        $this->method = $method;
        return $this;
    }

    public function getMethod(): string
    {
        return $this->method;
    }

    public function addButton(
        ?string $label  = null,
        ?string $icon   = null,
        ?string $action = null,
        bool    $submit = true,
    ): static
    {
        $this->buttons[] = (object) [
            'label'  => $label,
            'icon'   => $icon,
            'action' => $action,
            'submit' => $submit,
        ];
        return $this;
    }

    public function getButtons(): array
    {
        return $this->buttons;
    }

    public function getHeader(): ?string
    {
        return $this->header;
    }

    public function setHeader(?string $header): static
    {
        $this->header = $header;
        return $this;
    }

    public function getFooter(): ?string
    {
        return $this->footer;
    }

    public function setFooter(?string $footer): static
    {
        $this->footer = $footer;
        return $this;
    }

    public function storeError(string $error): static
    {
        if (!($storeKey = $this->getStoreKey('error'))) return $this;
        set_flash_var($storeKey, (string) $error);
        return $this;
    }

    public function getLastErrorStored(): ?string
    {
        if (!($storeKey = $this->getStoreKey('error'))) return null;
        if ($this->storedError !== false) return $this->storedError;
        $this->storedError = get_flash_var($storeKey);
        return $this->storedError;
    }

    public function isMultipart(?bool $multipart = null): bool | static
    {
        if ($multipart === null) return $this->multipart;
        $this->multipart = $multipart;
        return $this;
    }

    public function render(bool $asString = false): string | Microbe_DOM_Element
    {
        $div = dom('div.form');
        $form = dom('form')->appendTo($div);
        $form->attr('novalidate', true);
        if ($id = $this->getId()) $form->attr('id', $id);
        if ($url = $this->getUrl()) $form->attr('action', url($url));
        if ($method = $this->getMethod()) $form->attr('method', $method);
        if ($header = $this->getHeader()) dom('div.form-header')->append($header)->appendTo($form);

        if ($lastErrorStored = $this->getLastErrorStored()) dom('div.form-error')->appendText($lastErrorStored)->appendTo($form);

        $groups = dom('div.form-groups')->appendTo($form);

        foreach ($this->getChildren() as $child) $child->render()->appendTo($groups);

        if ($buttons = $this->getButtons()) {
            $actions = dom('div.form-actions')->appendTo($form);
            foreach ($buttons as $button) {
                $bt = dom('button')->appendTo($actions);
                if ($button->icon) $bt->append($this->formatIcon($button->icon));
                if ($button->label) dom('span')->append($button->label)->appendTo($bt);
                if ($button->submit) $bt->attr('type', 'submit');
                if ($button->action) $bt->attrs([ 'name' => 'action', 'value' => $button->action ]);
            }
        }

        if ($footer = $this->getFooter()) dom('div.form-footer')->append($footer)->appendTo($form);
        return $asString ? (string) $div : $div;
    }

    public function process(string $method = 'both'): Microbe_Form_Result
    {
        $fields = $this->getFields(recursive: true);
        $data = [];
        $errors = [];
        $hasError = false;
        foreach ($fields as $field) {
            $name = $field->getName();
            list($value, $errorCode) = $field->processValue(method: $method);
            $data[$name] = $value;
            $errors[$name] = $errorCode ? [
                'code'    => $errorCode,
                'message' => $field->getErrorMessage($errorCode),
            ] : false;
        }
        $this->lastResult = new Microbe_Form_Result($data, $errors);
        return $this->lastResult;
    }

    public function processAndRedirectOnError(string $url = '.'): ?Microbe_Form_Result
    {
        $result = $this->process();
        if ($result->hasErrors()) {
            $this->storeResult();
            $this->redirect($url);
        }
        return $result;
    }

    public function redirect(string $url = '.'): void
    {
        redirect($url);
    }

    public function getStoreKey(string $type): ?string
    {
        if (!($name = $this->getName())) return null;
        return 'forms.' . $name . '.' . $type;
    }

    public function storeResult(?Microbe_Form_Result $result = null): static
    {
        if (!($storeKey = $this->getStoreKey('results'))) return $this;
        if ($result === null) $result = $this->lastResult;
        if (!$result) throw new Microbe_Exception("Trying to store a result without giving a valid one and without a last result available");
        set_flash_var($storeKey, (string) $result);
        return $this;
    }

    public function getLastResultStored(): ?Microbe_Form_Result
    {
        if (!($storeKey = $this->getStoreKey('results'))) return null;
        if ($this->storedResult !== false) return $this->storedResult;
        $this->storedResult = ($stored = get_flash_var($storeKey)) ? new Microbe_Form_Result($stored) : null;
        return $this->storedResult;
    }

}

// ---{ Class: Microbe Form Html }---

class Microbe_Form_Html extends Microbe_Form_Element
{

    private string $html = '';

    public function setHtml(string $html): static
    {
        $this->html = $html;
        return $this;
    }

    public function render(bool $asString = false): string | Microbe_DOM_Element
    {
        $html = dom('div.form-html');
        $html->append($this->html);
        return $asString ? (string) $html : $html;
    }

}

// ---{ Class: Microbe Form Group }---

class Microbe_Form_Group extends Microbe_Form_Element
{

    public function addField(Microbe_Form_Field | string $field): Microbe_Form_Field
    {
        if (!($field instanceof Microbe_Form_Field)) $field = form_field($field, parent: $this);
        $this->addChild($field);
        return $field;
    }

    public function render(bool $asString = false): string | Microbe_DOM_Element
    {
        $group = dom('div.form-group');

        $lblStr = $this->getLabel();
        $icoStr = $this->getIcon();
        if ($lblStr || $icoStr) {
            $lbl = dom('div.form-group-label')->appendTo($group);
            if ($icoStr) $lbl->append($this->formatIcon($icoStr));
            if ($lblStr) dom('span')->append($lblStr)->appendTo($lbl);
        }

        if ($descStr = $this->getDescription()) {
            $desc = dom('div.form-group-description')->append($descStr)->appendTo($group);
        }

        $elems = dom('div.form-group-body')->appendTo($group);
        foreach ($this->getChildren() as $child) $child->render()->appendTo($elems);
        return $asString ? (string) $group : $group;
    }

}

// ---{ Class: Microbe Form Field }---

class Microbe_Form_Field extends Microbe_Form_Element
{

    const T_TEXT      = 'text';
    const T_PASSWORD  = 'password';
    const T_EMAIL     = 'email';
    const T_TEL       = 'tel';
    const T_URL       = 'url';
    const T_SEARCH    = 'search';
    const T_NUMBER    = 'number';
    const T_RADIO     = 'radio';
    const T_CHECKBOX  = 'checkbox';
    const T_TOGGLE    = 'checkbox/toggle';
    const T_FILE      = 'file';
    const T_RANGE     = 'range';
    const T_COLOR     = 'color';
    const T_DATE      = 'date';
    const T_TIME      = 'time';
    const T_HIDDEN    = 'hidden';

    const T_SELECT    = 'select';

    const T_TEXTAREA  = 'textarea';
    const T_RICHTEXT  = 'richtext';

    const T_CHECKLIST = 'checklist';

    const ATTRIBUTES = [
        'Name'            => [ 'attr' => 'name',           'cast' => 'string',                                                    'default' => null,    'scope' => null ],
        'Value'           => [ 'attr' => 'value',          'cast' => 'string',                                                    'default' => null,    'scope' => [ 'input' ] ],
        'Disabled'        => [ 'attr' => 'disabled',       'cast' => 'bool',                                                      'default' => null,    'scope' => null ],
        'Readonly'        => [ 'attr' => 'readonly',       'cast' => 'bool',                                                      'default' => null,    'scope' => [ 'textarea', 'richtext', 'text', 'password', 'email', 'tel', 'url', 'search', 'number', 'select', 'file', 'date', 'time' ] ],
        'Placeholder'     => [ 'attr' => 'placeholder',    'cast' => 'string',                                                    'default' => null,    'scope' => [ 'textarea', 'richtext', 'text', 'search', 'url', 'tel', 'email', 'password', 'number' ] ],
        'Checked'         => [ 'attr' => 'checked',        'cast' => 'bool',                                                      'default' => null,    'scope' => [ 'checkbox', 'checkbox/toggle', 'radio' ] ],
        'Required'        => [ 'attr' => 'required',       'cast' => 'bool',                                                      'default' => null,    'scope' => [ 'textarea', 'richtext', 'text', 'password', 'email', 'tel', 'url', 'search', 'number', 'select', 'radio', 'checkbox', 'checkbox/toggle', 'checklist', 'file', 'date', 'time' ] ],
        'Pattern'         => [ 'attr' => 'pattern',        'cast' => 'string',                                                    'default' => null,    'scope' => [ 'text', 'search', 'url', 'tel', 'email', 'password' ] ],
        'Multiple'        => [ 'attr' => 'multiple',       'cast' => 'bool',                                                      'default' => null,    'scope' => [ 'email', 'file' ] ],
        'Accept'          => [ 'attr' => 'accept',         'cast' => 'string',                                                    'default' => null,    'scope' => [ 'file' ] ],
        'Capture'         => [ 'attr' => 'capture',        'cast' => [ 'microphone', 'video', 'camera' ],                         'default' => null,    'scope' => [ 'file' ] ],
        'SpellChecked'    => [ 'attr' => 'spellcheck',     'cast' => 'bool', 'true' => 'true', 'false' => 'false',                'default' => 'false', 'scope' => [ 'textarea', 'richtext', 'text', 'search', 'url', 'email', 'tel' ] ],
        'AutoCorrected'   => [ 'attr' => 'autocorrect',    'cast' => 'bool', 'true' => 'on', 'false' => 'off',                    'default' => null,    'scope' => [ 'textarea' ] ],
        'AutoCapitalized' => [ 'attr' => 'autocapitalize', 'cast' => [ 'none', 'off', 'sentences', 'on', 'words', 'characters' ], 'default' => null,    'scope' => [ 'textarea', 'richtext', 'text', 'tel', 'search', 'number', 'select', 'radio', 'checkbox', 'checkbox/toggle', 'checklist', 'file', 'range', 'color', 'date', 'time', 'hidden' ] ],
        'AutoComplete'    => [ 'attr' => 'autocomplete',   'cast' => 'bool', 'true' => 'on', 'false' => 'off',                    'default' => null,    'scope' => [ 'textarea', 'richtext', 'text', 'password', 'email', 'tel', 'url', 'search', 'number', 'select', 'file', 'range', 'color', 'date', 'time', 'hidden' ] ],
        'List'            => [ 'attr' => 'list',           'cast' => 'string',                                                    'default' => null,    'scope' => [ 'text', 'email', 'tel', 'url', 'search', 'number', 'select', 'file', 'range', 'color', 'date', 'time' ] ],
        'Max'             => [ 'attr' => 'max',            'cast' => 'int',                                                       'default' => null,    'scope' => [ 'date', 'month', 'week', 'time', 'number', 'range' ] ],
        'Min'             => [ 'attr' => 'min',            'cast' => 'int',                                                       'default' => null,    'scope' => [ 'date', 'month', 'week', 'time', 'number', 'range' ] ],
        'Step'            => [ 'attr' => 'step',           'cast' => 'float',                                                     'default' => null,    'scope' => [ 'date', 'month', 'week', 'time', 'number', 'range' ] ],
        'MaxLength'       => [ 'attr' => 'maxlength',      'cast' => 'int',                                                       'default' => null,    'scope' => [ 'textarea', 'richtext', 'text', 'search', 'url', 'tel', 'email', 'password' ] ],
        'MinLength'       => [ 'attr' => 'minlength',      'cast' => 'int',                                                       'default' => null,    'scope' => [ 'textarea', 'richtext', 'text', 'search', 'url', 'tel', 'email', 'password' ] ],
        'Size'            => [ 'attr' => 'size',           'cast' => 'int',                                                       'default' => null,    'scope' => [ 'text', 'search', 'url', 'tel', 'email', 'password' ] ],
        'AutoFocused'     => [ 'attr' => 'autofocus',      'cast' => 'bool',                                                      'default' => null,    'scope' => [ 'textarea' ] ],
        'Cols'            => [ 'attr' => 'cols',           'cast' => 'int',                                                       'default' => null,    'scope' => [ 'textarea', 'richtext' ] ],
        'Rows'            => [ 'attr' => 'rows',           'cast' => 'int',                                                       'default' => null,    'scope' => [ 'textarea', 'richtext' ] ],
        'Wrapped'         => [ 'attr' => 'wrap',           'cast' => [ 'hard', 'soft', 'off' ],                                   'default' => null,    'scope' => [ 'textarea', 'richtext' ] ],
    ];

    // ==={ Static }============================================================

    public static function getTypes(): array
    {
        return get_class_constants(static::class, 'T_*');
    }

    public static function isType(string $type): bool
    {
        return in_array($type, static::getTypes());
    }

    // ==={ Entity }============================================================

    private ?string $type = null;
    private ?object $classes = null;
    private ?object $customAttrs = null;
    private mixed $defaultValue = null;
    private bool $passwordStrengthIndicator = false;
    private ?float $passwordMinScore = null;
    private ?string $altLabel = null;
    private bool $isMandatory = false;
    private ?string $validationRegExp = null;
    private ?Closure $validationFunction = null;
    private array $choices = [];
    private array $attrs = [];

    public function __construct(string $type)
    {
        parent::__construct();

        if (!static::isType($type)) throw new Microbe_Exception("Trying to instanciate a Microbe_Form_Field with an invalid type: {$type}.");

        $this->type = $type;

        $this->classes = (object) [ 'root' => [], 'label' => [], 'ctrl' => [] ];
        $this->customAttrs = clone $this->classes;
    }

    public function __call(string $func, array $args): mixed
    {
        $value = $args[0] ?? null;

        if (!preg_match('/^(?<method>get|set|is)(?<attr>[A-Z][A-Za-z0-9]*)$/', $func, $m)) throw new Microbe_Exception("Invalid method format while calling Microbe_Form_Field::{$func}.");
        $method = $m['method'];
        $attrName = $m['attr'];
        if (!($attr = (static::ATTRIBUTES[$attrName] ?? null))) throw new Microbe_Exception("Unknown Microbe_Form_Field attribute: {$attrName}.");
        $attr = (object) $attr;

        if ($attr->scope !== null && !in_array($this->type, $attr->scope)) throw new Microbe_Exception("Trying to get/set '{$attrName}' on a non applyable field type ('{$this->type}').");

        if ($method === 'is' && $attr->cast !== 'bool') throw new Microbe_Exception("Using method is on a non-boolean attribute: {$func}.");
        else if ($method !== 'is' && $attr->cast === 'bool') throw new Microbe_Exception("You should use " . preg_replace('/^(get|set)/', 'is', $func) . " to get/set the value of the boolean attribute {$attrName}.");

        if ($method === 'get' || ($method === 'is' && $value === null)) return $this->attrs[$attrName] ?? null;

        if ($attr->cast === 'bool' && $value !== null && !is_bool($value)) throw new Microbe_Exception("Trying to set a non-boolean and non-null value to a boolean attribute: {$attrName}.");

        if ($value !== null) $this->attrs[$attrName] = $value;
        else if (array_key_exists($attrName, $this->attrs)) unset($this->attrs[$attrName]);
        return $this;
    }

    public function getType(): ?string { return $this->type; }

    public function isMandatory(?bool $isMandatory = null): bool | static
    {
        if ($isMandatory === null) return $this->isMandatory;
        $this->isMandatory = $isMandatory;
        return $this;
    }

    public function setValidationRegExp(?string $validationRegExp = null): static
    {
        $this->validationRegExp = $validationRegExp;
        return $this;
    }

    public function getValidationRegExp(): ?string
    {
        return $this->validationRegExp;
    }

    public function setValidationFunction(?Closure $validationFunction = null): static
    {
        $this->validationFunction = $validationFunction;
        return $this;
    }

    public function getValidationFunction(): ?Closure
    {
        return $this->validationFunction;
    }

    public function setDefaultValue(mixed $defaultValue = null, ?string $defaultValueGetter = null): static
    {
        if ($defaultValueGetter && !$defaultValue) return $this;

        if (is_array($defaultValue)) {
            if (array_key_exists($defaultValueGetter, $defaultValue)) throw new Microbe_Exception("Default value array doesn't contains given getter key.");
            $defaultValue = $defaultValue->$defaultValueGetter;
        }

        if (is_object($defaultValue)) {
            if (property_exists($defaultValue, $defaultValueGetter)) return $defaultValue->$defaultValueGetter;
            $defaultValue = $defaultValue->{$defaultValueGetter}();
        }

        $this->defaultValue = $defaultValue;
        return $this;
    }

    public function getDefaultValue(): mixed
    {
        return $this->defaultValue;
    }

    public function getAttributes(): mixed
    {
        return $this->attrs;
    }

    public function addClass(string $className, string $target = 'root'): static
    {
        $this->classes->{$target}[] = $className;
        return $this;
    }

    public function clearClasses(string $target = 'root'): static
    {
        $this->classes->{$target} = [];
        return $this;
    }

    public function getClasses(string $target = 'root', bool $asString = false): array | string
    {
        $cl = $this->classes->{$target} ?? [];
        return $asString ? $cl : implode(' ', $cl);
    }

    public function setCustomAttribute(string $key, string | bool | int | float $value, string $target = 'root'): static
    {
        if ($value !== false) $this->customAttrs->{$target}[$key] = $value;
        else if (array_key_exists($key, $this->customAttrs->{$target})) unset($this->customAttrs->{$target}[$key]);
        return $this;
    }

    public function clearCustomAttributes(string $target = 'root'): static
    {
        $this->customAttrs->{$target} = [];
        return $this;
    }

    public function getCustomAttributes(string $target = 'root'): array | string
    {
        return $this->customAttrs->{$target};
    }

    public function addChoice(string | int | float $value, ?string $label = null, ?string $icon = null): static
    {
        $this->choices[] = (object) [
            'value' => $value,
            'label' => $label ?? $value,
            'icon'  => $icon,
        ];
        return $this;
    }

    public function setChoices(array | string $choices, bool $clear = true): static
    {
        if ($clear) $this->clearChoices();

        if ($choices === 'countries') {
            $this->addChoice(value: '', label: t("Select Country"));
            foreach (get_countries() as $country) $this->addChoice(value: $country->code3, label: $country->name);
        } else if ($choices === 'languages') {
            $this->addChoice(value: '', label: t("Select Language"));
            foreach (get_languages() as $language) $this->addChoice(value: $language->code3, label: $language->name);
        } else if (is_array($choices)) {
            foreach ($choices as $choice) {
                if (!array_key_exists('value', $choice)) throw new Microbe_Exception("Trying to set a choice without a valid value.");
                $this->addChoice(
                    value: $choice['value'],
                    label: $choice['label'] ?? null,
                    icon:  $choice['icon'] ?? null,
                );
            }
        } else {
            throw new Microbe_Exception("Invalid choices array or keyword.");
        }

        return $this;
    }

    public function clearChoices(): static
    {
        $this->choices = [];
        return $this;
    }

    public function getChoices(): array
    {
        return $this->choices;
    }

    public function setAltLabel(?string $altLabel = null): static
    {
        $this->altLabel = $altLabel;
        return $this;
    }

    public function getAltLabel(): ?string
    {
        return $this->altLabel;
    }

    public function assertMinScore(?float $score = null): static
    {
        $this->passwordMinScore = $score;
        return $this;
    }

    public function enablePasswordStrengthIndicator(): static
    {
        $this->passwordStrengthIndicator = true;
        return $this;
    }

    public function disablePasswordStrengthIndicator(): static
    {
        $this->passwordStrengthIndicator = false;
        return $this;
    }

    public function isPasswordStrengthIndicator(): bool
    {
        return $this->passwordStrengthIndicator;
    }

    public function render(bool $asString = false): string | Microbe_DOM_Element
    {
        $type = $this->getType();
        $name = $this->getName();
        $form = $this->getForm();

        $lastResult = $form ? $form->getLastResultStored() : null;
        $lastError = $name && $lastResult ? $lastResult->getError($name) : null;
        $lastValue = $name && $lastResult ? $lastResult->get($name) : null;

        $lblStr = $this->getLabel();
        $iconStr = $this->getIcon();

        $field = dom('div.field')->addClass('field-' . $type);
        if (!$lblStr && !$iconStr) $field->addClasses('field-no-label');
        if ($cl = $this->getClasses(target: 'root')) $field->addClasses($cl);
        if ($attrs = $this->getCustomAttributes(target: 'root')) $field->attrs($attrs);

        $lbl = dom('label.field-label')->appendTo($field);
        if ($iconStr) $lbl->append($this->getForm()->formatIcon($iconStr));
        if ($lblStr) dom('div')->append($lblStr)->appendTo($lbl);

        if ($cl = $this->getClasses(target: 'label')) $lbl->addClasses($cl);
        if ($attrs = $this->getCustomAttributes(target: 'label')) $lbl->attrs($attrs);

        $ctrl = dom('div.field-ctrl')->appendTo($field);
        if ($cl = $this->getClasses(target: 'ctrl')) $ctrl->addClasses($cl);
        if ($attrs = $this->getCustomAttributes(target: 'ctrl')) $ctrl->attrs($attrs);

        if ($descStr = $this->getDescription()) {
            $desc = dom('label.field-description')->append($descStr)->appendTo($field);
        }

        if ($lastError && is_object($lastError)) {
            dom('div.field-error')->append($lastError->message ?? $lastError->code ?? 'unknown')->appendTo($field);
        }

        $defaultValue = $lastValue ?: $this->getDefaultValue();
        $inputs = [];

        if ($type === static::T_SELECT) {

            $inputs[] = $input = dom('select')->appendTo($ctrl);
            foreach ($this->getChoices() as $choice) {
                $opt = dom('option')
                    ->attr('value', $choice->value)
                    ->append($choice->label)
                    ->appendTo($input);
                if ($choice->value === $defaultValue) $opt->attr('selected', true);
            }

        } else if ($type === static::T_CHECKLIST || $type === static::T_RADIO) {

            $input = dom('ul')->appendTo($ctrl);

            foreach ($this->getChoices() as $choice) {
                $li = dom('li')->appendTo($input);
                $liLbl = dom('label')->appendTo($li);
                $inputs[] = $checkbox = dom('input')
                    ->attrs([ 'type' => $type === static::T_RADIO ? 'radio' : 'checkbox', 'value' => $choice->value ])
                    ->appendTo($liLbl);
                if ($choice->value === $defaultValue) $checkbox->attr('checked', true);
                dom('span')->append($choice->label)->appendTo($liLbl);
            }

        } else if ($type === static::T_TEXTAREA || $type === static::T_RICHTEXT) {

            $inputs[] = $input = dom('textarea')->appendTo($ctrl);

            if ($defaultValue) $input->append(esc($defaultValue));

        } else if ($type === static::T_CHECKBOX || $type === static::T_TOGGLE) {

            $inputLbl = dom('label')->appendTo($ctrl);

            $inputs[] = $input = dom('input')
                ->appendTo($inputLbl)
                ->attrs([ 'type' => 'checkbox', 'value' => '1' ]);

            if ($defaultValue) $input->attr('checked', true);

            if ($altLabel = $this->getAltLabel()) $span = dom('span')->append($altLabel)->appendTo($inputLbl);

        } else {

            $inputs[] = $input = dom('input')
                ->appendTo($ctrl)
                ->attr('type', preg_replace('/^([^\/]+)(\/.*)?$/', '$1', $type));

            if ($defaultValue) $input->attr('value', $defaultValue);

            if ($type === static::T_PASSWORD && $this->isPasswordStrengthIndicator()) {
                //
            }

        }

        foreach ($inputs as $input) {
            $attrs = [];

            foreach ($this->getAttributes() as $attrName => $attrValue) {
                if ($attrValue === null) continue;
                if (!($attr = (static::ATTRIBUTES[$attrName] ?? null))) continue;
                $attr = (object) $attr;
                $attrs[$attrName] = true;
                if ($attr->cast === 'bool') {
                    if ($attrValue) {
                        $input->attr($attr->attr, ($true = $attr->true ?? null) === null ? true : $true);
                    } else if ($false = ($attr->false ?? null)) {
                        $input->attr($attr->attr, $false);
                    }
                } else if (is_array($attr->cast)) {
                    if (in_array($attrValue, $attr->cast)) {
                        $input->attr($attr->attr, (string) $attrValue);
                    }
                } else {
                    $input->attr($attr->attr, (string) $attrValue);
                }
            }

            foreach (static::ATTRIBUTES as $attrName => $attr) {
                $attr = (object) $attr;
                if ($attr->default === null) continue;
                if ($attr->scope !== null && !in_array($this->type, $attr->scope)) continue;
                if ($attrs[$attrName] ?? false) continue;
                $input->attr($attr->attr, (string) $attr->default);
            }
        }

        return $asString ? (string) $field : $field;
    }

    protected function processValueValidation(mixed $value): array
    {
        $err = false;
        if (($regExp = $this->getValidationRegExp()) && is_string($value) && !preg_match($regExp, $value)) $err = 'invalid_regexp';
        if ($func = $this->getValidationFunction()) $err = $func($value);
        return [ $value, $err ];
    }

    public function processValue(string $method = 'both', mixed $value = null): array
    {
        $type = $this->getType();

        if ($type === static::T_FILE) {
            return [ null, 'unhandled_yet' ];
        }

        if ($value === null) $value = get($this->getName(), method: $method);

        $multiple = $type === static::T_CHECKLIST;

        if ($type === static::T_CHECKLIST) {
            if (!$value) return [ [], false ];
            if (!is_array($value)) return [ [], 'invalid_array' ];
            return $this->processValueValidation(array_values(array_filter(array_map(function(mixed $v) use ($type): mixed
            {
                if (!is_scalar($v)) return null;
                if (!$v) return null;
                return (string) $v;
            }))));
        }

        if ($value !== null && !is_scalar($value)) return [ null, 'invalid_scalar' ];

        if ($type === static::T_CHECKBOX || $type === static::T_TOGGLE) {
            return $this->processValueValidation(value_seems_true($value));
        }

        if ($value === '' || $value === null) {
            if ($this->isMandatory()) return [ null, 'mandatory' ];
            return $this->processValueValidation(null);
        }

        $value = (string) $value;
        if ($type === static::T_NUMBER || $type === static::T_RANGE) {
            if (!is_float_val($value)) return [ null, 'invalid_number' ];
            $value = (float) $value;
            if ((($min = $this->getMin()) !== null) && ($value < $min)) return [ $value, 'below_min' ];
            if ((($max = $this->getMax()) !== null) && ($value > $max)) return [ $value, 'above_max' ];
            return $this->processValueValidation($value);
        }

        if ($type === static::T_DATE) {
            if (!($dt = build_datetime_from_form(date: $value))) return [ null, 'invalid_date' ];
            return $this->processValueValidation($dt->format('Y-m-d'));
        }

        if ($type === static::T_TIME) {
            if (!($dt = build_datetime_from_form(time: $value))) return [ null, 'invalid_time' ];
            return $this->processValueValidation($dt->format('H:i:s'));
        }

        if ($type === static::T_EMAIL) {
            if (!is_valid_email_address($value)) return [ $value, 'invalid_email' ];
            return $this->processValueValidation($value);
        }

        if ($type === static::T_PASSWORD) {
            if (($this->passwordMinScore !== null) && (compute_password_score($value) < $this->passwordMinScore)) return [ $value, 'weak_password' ];
            return $this->processValueValidation($value);
        }

        if ($type === static::T_TEL) {
            if (!($tel = cast_phone_number($value))) return [ $value, 'invalid_phone' ];
            return $this->processValueValidation($tel);
        }

        if ($type === static::T_URL) {
            if (!is_valid_url($value)) return [ $value, 'invalid_url' ];
            return $this->processValueValidation($value);
        }

        if ($type === static::T_COLOR) return [];

        $len = strlen($value);

        if (in_array($type, static::ATTRIBUTES['MinLength']['scope'])) {
            if (($minLength = $this->getMinLength()) !== null && ($len < $minLength)) return [ $value, 'below_min_length' ];
        }

        if (in_array($type, static::ATTRIBUTES['MaxLength']['scope'])) {
            if (($maxLength = $this->getMaxLength()) !== null && ($len > $maxLength)) return [ $value, 'above_max_length' ];
        }

        return $this->processValueValidation($value);
    }

}

// ---{ Class: Microbe Form Result }---

class Microbe_Form_Result
{

    // ==={ Entity }============================================================

    private array $data = [];
    private array $errors = [];

    public function __construct(string | array $data = [], array $errors = [])
    {
        if (is_string($data)) {
            if (!($d = json_decode($data, true))) throw new Microbe_Exception("Trying to initialize a Microbe_Form_Result without a valid JSON string or two arrays data/errors");
            if (!is_array($d)) throw new Microbe_Exception("Trying to initialize a Microbe_Form_Result without a valid string data/errors JSON object");
            $data = $d['data'] ?? [];
            $errors = $d['errors'] ?? [];
        }

        $this->data = $data;
        $this->errors = $errors;
    }

    public function __toString(): string
    {
        return json_encode([ 'data' => $this->data, 'errors' => $this->errors ]);
    }

    public function getErrors(bool $asObject = true): object | array
    {
        return $asObject ? (object) array_map(function(array | false $error): ?object
        {
            return $error ? (object) $error : false;
        }, $this->errors) : $this->errors;
    }

    public function getError(string $field): object | false
    {
        if (!($error = $this->getErrors(false)[$field])) return false;
        return (object) $error;
    }

    public function getErrorCode(string $field): string | false
    {
        if (!($err = $this->getError($field))) return false;
        return $err->code ?? false;
    }

    public function getErrorMessage(string $field, bool $fallback = true): string | false
    {
        if (!($err = $this->getError($field))) return false;
        return $err->message ?? ($fallback ? $err->code : false);
    }

    public function hasErrors(): bool
    {
        return count(array_filter(array_values($this->getErrors(false)))) > 0;
    }

    public function get(string | array | null $fields = null, bool $asObject = true): mixed
    {
        if ($fields === null) return $asObject ? (object) $this->data : $this->data;
        if (!is_array($fields)) return $this->data[$fields] ?? null;
        $values = [];
        foreach ($fields as $fieldName) $values[$fieldName] = $this->data[$fieldName] ?? null;
        return $asObject ? (object) $values : $values;
    }

}

// ---{ Class: Microbe Data Validation Response }---

class Microbe_Data_Validation_Response
{

    // ==={ Entity }============================================================

    public array $fields = [];
    public array $data = [];
    public array $errors = [];

    public function setField(object $field): self
    {
        $this->fields[$field->name] = $field;
        $this->data[$field->name] = $field->value;

        if ($field->error) $this->errors[$field->name] = $field->error;
        else if (isset($this->errors[$field->name])) unset($this->errors[$field->name]);

        return $this;
    }

    public function getErrors(
        bool $onlyCodes      = false,
        bool $onlyMessages   = false,
        bool $asSimpleArrays = false,
    ): array
    {
        if (!$onlyCodes && !$onlyMessages && !$asSimpleArrays) return $this->errors;
        return array_map(function(Microbe_Data_Validation_Error $error) use ($onlyCodes, $onlyMessages, $asSimpleArrays): string | array | null
        {
            if ($asSimpleArrays) return [ 'code' => $error->getCode(), 'message' => $error->getMessage() ];
            if ($onlyCodes) return $error->getCode();
            if ($onlyMessages) return $error->getMessage();
            return null;
        }, $this->errors);
    }

    public function getErrorsCodes(): array
    {
        return $this->getErrors(onlyCodes: true);
    }

    public function getErrorsMessages(): array
    {
        return $this->getErrors(onlyMessages: true);
    }

    public function getData(): object
    {
        return $this->data;
    }

    public function get(string $name): mixed
    {
        return $this->data[$name] ?? null;
    }

    public function hasErrors(): bool
    {
        return !empty($this->errors);
    }

    public function isSuccess(): bool
    {
        return !$this->hasErrors();
    }

    public function outputErrorsJson(?string $message = null, bool $close = true): bool
    {
        if (!$this->hasErrors()) return false;
        json_error('form', [
            'message' => $message ?: t("Please review the errors."),
            'errors'  => $this->getErrors(asSimpleArrays: true),
        ], close: $close);
        return true;
    }

}

// ---{ Class: Microbe Data Validation Error }---

class Microbe_Data_Validation_Error
{

    // ==={ Entity }============================================================

    private ?string $code    = null;
    private ?string $message = null;

    public function __construct(?string $code = null, ?string $message = null)
    {
        $this->code = $code;
        $this->setMessage($message);
    }

    public function getCode(): ?string
    {
        return $this->code;
    }

    public function setMessage(?string $message = null): self
    {
        $this->message = $message;
        return $this;
    }

    public function getMessage(): ?string
    {
        return $this->message;
    }

    public function hasMessage(): bool
    {
        return $this->getMessage() !== null;
    }

    public function __toString(): string
    {
        return $this->getMessage() ?: '';
    }

}
