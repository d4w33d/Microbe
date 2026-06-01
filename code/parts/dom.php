<?php

// =============================================================================
// ---{ Functions }-------------------------------------------------------------

/**
 * <USER>
 * Returns a Microbe_DOM_Element, helping to build HTML elements.
 * @param  string $tag         Tag name, optionnaly containing classes and ID
 *                             as . and # suffixes.
 * @return Microbe_DOM_Element The created Microbe_DOM_Element.
 */
function dom(string $tag): Microbe_DOM_Element
{
    return new Microbe_DOM_Element($tag);
}

// ---{ Class: Microbe DOM Element }---

class Microbe_DOM_Element
{

    public const AUTOCLOSE_TAGS = [ 'area', 'base', 'br', 'col', 'embed', 'hr', 'img', 'input', 'link', 'meta', 'param', 'source', 'track', 'wbr' ];

    private string $tag;
    private array $attrs = [];
    private array $children = [];

    public function __construct(string $tag)
    {
        if (!preg_match('/^(?<tag>[a-z0-9_-]+)(?<cl1>\.[^#]+)?(?<id>#[^.#]+)?(?<cl2>\.[^#]+)?$/i', $tag, $m)) throw new Microbe_Exception("Unable to parse Microbe_DOM_Element's tag name");
        $m = array_merge([ 'cl1' => '', 'cl2' => '', 'id' => '' ], $m);
        if ($id = trim($m['id'], '#')) $this->attr('id', $id);
        if ($cl = implode(' ', array_values(array_filter(explode('.', $m['cl1'] . '.' . $m['cl2']))))) $this->attr('class', $cl);
        $this->tag = strtolower($m['tag']);
    }

    public function __toString(): string
    {
        return $this->html();
    }

    public function getChildren(): array
    {
        return $this->children;
    }

    public function hasChildren(): bool
    {
        return count($this->children) > 0;
    }

    public function attr(string $k, mixed $v = null): mixed
    {
        if ($v === null) return $this->attrs[$k] ?? null;
        if ($v !== false) $this->attrs[$k] = $v;
        else if (array_key_exists($k, $this->attrs)) unset($this->attrs[$k]);
        return $this;
    }

    public function attrs(array $kv): self
    {
        $this->attrs = array_merge($this->attrs, $kv);
        return $this;
    }

    public function addClass(string $class): self
    {
        $current = $this->attr('class') ?: '';
        $current .= ' ' . implode(' ', array_filter(array_map('trim', preg_split('/[.,\s]/', $class))));
        $this->attr('class', trim($current));
        return $this;
    }

    public function addClasses(array | string $classes): self
    {
        if (is_string($classes)) $classes = [ $classes ];
        foreach ($classes as $cl) $this->addClass($cl);
        return $this;
    }

    public function css(array $properties): self
    {
        if (!$properties) return $this;
        $current = $this->attr('style') ?: '';
        foreach ($properties as $k => $v) $current .= ' ' . $k . ': ' . $v . ';';
        $this->attr('style', trim($current));
        return $this;
    }

    public function append(self | array | string | int | float $child): self
    {
        if (is_array($child)) foreach ($child as $c) $this->append($c);
        else $this->children[] = $child;
        return $this;
    }

    public function appendText(string | int | float $text): self
    {
        return $this->append(esc($text));
    }

    public function prepend(self | array | string | int | float $child): self
    {
        if (is_array($child)) foreach ($child as $c) $this->prepend($c);
        else array_unshift($this->children, $child);
        return $this;
    }

    public function prependText(string | int | float $text): self
    {
        return $this->prepend(esc($text));
    }

    public function appendTo(self $parent): self
    {
        $parent->append($this);
        return $this;
    }

    public function prependTo(self $parent): self
    {
        $parent->prepend($this);
        return $this;
    }

    public function html(): string
    {
        $attrs = [];
        foreach ($this->attrs as $k => $v) {
            if ($v === null || $v === false) continue;
            $attrs[] = $k . ($v === true ? '' : ('="' . esc((string) $v) . '"'));
        }
        $html = '<' . $this->tag . ($attrs ? ' ' . implode(' ', $attrs) : '') . '>';
        if (in_array($this->tag, self::AUTOCLOSE_TAGS)) return $html;
        foreach ($this->getChildren() as $child) $html .= $child instanceof self ? $child->html() : $child;
        $html .= '</' . $this->tag . '>';
        return $html;
    }

}

/**
 * <USER>
 * Returns an array containing the useful pages numbers,
 * and null entries for ellipsis.
 * @param  int    $page  Current page number.
 * @param  int    $total Total pages.
 * @return array         Array of pages numbers.
 */
function get_pagination_numbers(int $page, int $total): array
{
    $numbers = [];
    if ($total < 1 || $page > $total) return $numbers;
    $numbers[] = 1;
    $i = max(2, $page - 5);
    if ($i > 2) $numbers[] = null;
    for (; $i < min($page + 6, $total); $i++) $numbers[] = $i;
    if ($i !== $total) $numbers[] = null;
    $numbers[] = $total;
    return $numbers;
}

/**
 * <USER>
 * Create a Microbe_Pagination instance.
 * @param  string             $var          Variable name.
 * @param  string|null        $url          Url format (default:
 *                                          url('.', [ $var => '{{page}}' ])).
 * @param  int                $totalItems   Total items of the collection.
 * @param  int                $itemsPerPage Items shown per page.
 * @return Microbe_Pagination               The instance.
 */
function pagination(
    string  $var          = 'page',
    ?string $url          = null,
    int     $totalItems   = 1,
    int     $itemsPerPage = 9,
): Microbe_Pagination
{
    return new Microbe_Pagination(var: $var, url: $url, totalItems: $totalItems, itemsPerPage: $itemsPerPage);
}

class Microbe_Pagination
{

    private ?string $var = null;
    private ?string $url = null;

    private int $totalItems = 1;
    private int $currentPage = 1;
    private int $itemsPerPage = 9;

    public function getTotalItems(): int { return $this->totalItems; }
    public function getCurrentPage(): int { return $this->currentPage; }
    public function getItemsPerPage(): int { return $this->itemsPerPage; }
    public function getTotalPages(): int { return (int) ceil($this->getTotalItems() / $this->getItemsPerPage()); }
    public function getOffset(): int { return ($this->getCurrentPage() - 1) * $this->getItemsPerPage(); }
    public function getShownItems(): int { return min($this->getItemsPerPage(), $this->getTotalItems() - $this->getOffset()); }

    public function __construct(
        string $var          = 'page',
        ?string $url         = null,
        int    $totalItems   = 1,
        int    $itemsPerPage = 9,
    )
    {
        $this->var = $var;
        $this->url = $url ?: url('.', [ $var => '{{page}}' ]);
        $this->totalItems = $totalItems;
        $this->itemsPerPage = $itemsPerPage;
        $this->currentPage = get_page_number(var: $this->var, max: $this->getTotalPages());
    }

    public function html(string $labelPrevious = "Previous", string $labelNext = "Next", array $attrs = []): string
    {
        $currentPage = $this->getCurrentPage();
        $totalPages = $this->getTotalPages();
        if ($totalPages <= 1) return '';

        $prevPage = $currentPage > 1 ? $currentPage - 1 : null;
        $nextPage = $currentPage < $totalPages ? $currentPage + 1 : null;
        $pageUrl = function(int $p): string
        {
            $u = $this->url;
            if ($p <= 1) $u = preg_replace('/([?&])[^?&=]+=\{\{page\}\}/', '$1', $u);
            $u = str_replace('{{page}}', (string) $p, $u);
            $u = trim($u, '?%');
            return $u;
        };

        $container = dom('div.pagination')
            ->attrs($attrs)
            ->append($div = dom('div'));

        $div
            ->append($prev = dom('span.pagination-prev'))
            ->append($ul = dom('ul'))
            ->append($next = dom('span.pagination-next'));

        $prev->append(($prevPage ? dom('a')->attr('href', $pageUrl($prevPage)) : dom('span'))->append($labelPrevious));
        $next->append(($nextPage ? dom('a')->attr('href', $pageUrl($nextPage)) : dom('span'))->append($labelNext));

        foreach (get_pagination_numbers($currentPage, $totalPages) as $p) {
            $li = dom('li')->appendTo($ul);
            if ($p === null) {
                dom('em')->append('&hellip;')->appendTo($li);
            } else {
                $el = dom('a')->attr('href', $pageUrl($p))->append($p)->appendTo($li);
                if ($p === $currentPage) $li->addClass('active');
            }
        }

        return $container->html();
    }

    public function render(?string $labelPrevious = null, ?string $labelNext = null, array  $attrs = []): static
    {
        if ($labelPrevious === null) $labelPrevious = icon('arrow-left') . ' <span>' . t("Previous Page") . '</span>';
        if ($labelNext === null) $labelNext = '<span>' . t("Previous Page") . '</span> ' . icon('arrow-right');
        echo $this->html(labelPrevious: $labelPrevious, labelNext: $labelNext, attrs: $attrs);
        return $this;
    }

    public function countingSentence(
        string $none    = "No item to show.",
        string $one     = "Showing {shown} of 1 item.",
        string $several = "Showing {shown} of {total} items.",
    ): string
    {
        $totalItems = $this->getTotalItems();
        $shownItems = $this->getShownItems();
        $args = [ 'total' => $totalItems, 'shown' => $shownItems ];
        if ($totalItems <= 0) return t($none, $args);
        if ($totalItems === 1) return t($one, $args);
        return t($several, $args);
    }

}
