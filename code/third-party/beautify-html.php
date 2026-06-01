<?php
/**
 * --------------------------------------------------------------------------------------------
 * | Microbe sincerely thanks Beautify_Html authors (Ivan Weiler) / 2023-07-27T21:07:07+00:00 |
 * | Scraped manually and tinkered without any refinement, based on the following URL:        |
 * |   - https://raw.githubusercontent.com/ivanweiler/beautify-html/master/beautify-html.php  |
 * | The constructor was simplified.                                                          |
 * | The styles/scripts beautifier system was removed.                                        |
 * | The method 'set_options' was removed.                                                    |
 * --------------------------------------------------------------------------------------------
 * Beautify_Html class
 * The MIT License (MIT)
 * Copyright (c) 2007-2013 Einar Lielmanis and contributors.
 * Permission is hereby granted, free of charge, to any person
 * obtaining a copy of this software and associated documentation files
 * (the "Software"), to deal in the Software without restriction,
 * including without limitation the rights to use, copy, modify, merge,
 * publish, distribute, sublicense, and/or sell copies of the Software,
 * and to permit persons to whom the Software is furnished to do so,
 * subject to the following conditions:
 * The above copyright notice and this permission notice shall be
 * included in all copies or substantial portions of the Software.
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND,
 * EXPRESS OR IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF
 * MERCHANTABILITY, FITNESS FOR A PARTICULAR PURPOSE AND
 * NONINFRINGEMENT. IN NO EVENT SHALL THE AUTHORS OR COPYRIGHT HOLDERS
 * BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER LIABILITY, WHETHER IN AN
 * ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM, OUT OF OR IN
 * CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 * SOFTWARE.
 * PHP port by Ivan Weiler, 2014
 */
class Microbe_Beautify_Html
{

    private $options = [
        'indent_inner_html' => true,
        'indent_size' => 2,
        'indent_char' => ' ',
        'indent_scripts' => 'normal',
        'wrap_line_length' => 32786,
        'unformatted' => ['code', 'pre'],
        'preserve_newlines' => false,
        'max_preserve_newlines' => 32786,
    ];

    private $pos;
    private $current_mode;
    private $tags;
    private $tag_type;
    private $token_text;
    private $last_token;
    private $last_text;
    private $token_type;
    private $newlines;
    private $indent_content;
    private $indent_level;
    private $line_char_count;
    private $indent_string;
    private $input_length;
    private $input;
    private $output;
    private $whitespace = [ "\n", "\r", "\t", ' ' ];
    private $single_token = [ 'br', 'input', 'link', 'meta', '!doctype', 'basefont', 'base', 'area', 'hr', 'wbr', 'param', 'img', 'isindex', '?xml', 'embed', '?php', '?', '?=' ];
    private $extra_liners = [ 'head', 'body', '/html' ];

    public function __construct(array $options = [])
    {
        foreach ($options as $k => $v) $this->options[$k] = $v;

        $this->pos = 0;
        $this->current_mode = 'CONTENT';
        $this->tags = [ 'parent' => 'parent1', 'parentcount' => 1, 'parent1' => '' ];
        $this->tag_type = '';
        $this->token_text = $this->last_token = $this->last_text = $this->token_type = '';
        $this->newlines = 0;
        $this->indent_content = $this->options['indent_inner_html'];
        $this->indent_level = 0;
        $this->line_char_count = 0;
        $this->indent_string = str_repeat($this->options['indent_char'], $this->options['indent_size']);
    }

    private function traverse_whitespace()
    {
        $input_char = isset($this->input[$this->pos]) ? $this->input[$this->pos] : '';
        if (!$input_char || !in_array($input_char, $this->whitespace)) {
            return false;
        }
        $this->newlines = 0;
        while ($input_char && in_array($input_char, $this->whitespace)) {
            if ($this->options['preserve_newlines'] && $input_char === "\n" && $this->newlines <= $this->options['max_preserve_newlines']) {
                $this->newlines += 1;
            }
            $this->pos++;
            $input_char = isset($this->input[$this->pos]) ? $this->input[$this->pos] : '';
        }
        return true;
    }

    private function get_content()
    {
        $input_char = '';
        $content = [];
        $space = false;
        while (isset($this->input[$this->pos]) && $this->input[$this->pos] !== '<') {
            if ($this->pos >= $this->input_length) {
                return count($content) ? implode('', $content) : ['', 'TK_EOF'];
            }
            if ($this->traverse_whitespace()) {
                if (count($content)) $space = true;
                continue;
            }
            $input_char = $this->input[$this->pos];
            $this->pos++;
            if ($space) {
                if ($this->line_char_count >= $this->options['wrap_line_length']) {
                    $this->print_newline(false, $content);
                    $this->print_indentation($content);
                } else {
                    $this->line_char_count++;
                    $content[] = ' ';
                }
                $space = false;
            }
            $this->line_char_count++;
            $content[] = $input_char;
        }
        return count($content) ? implode('', $content) : '';
    }

    private function get_contents_to($name)
    {
        if ($this->pos === $this->input_length) return ['', 'TK_EOF'];
        $input_char = '';
        $content = '';
        $reg_array = [];
        preg_match('#</' . preg_quote($name, '#') . '\\s*>#im', $this->input, $reg_array, PREG_OFFSET_CAPTURE, $this->pos);
        $end_script = $reg_array ? ($reg_array[0][1]) : $this->input_length;
        if ($this->pos < $end_script) {
            $content = substr($this->input, $this->pos, max($end_script-$this->pos, 0));
            $this->pos = $end_script;
        }
        return $content;
    }

    private function record_tag($tag)
    {
        if (isset($this->tags[$tag . 'count'])) {
            $this->tags[$tag . 'count']++;
            $this->tags[$tag . $this->tags[$tag . 'count']] = $this->indent_level;
        } else {
            $this->tags[$tag . 'count'] = 1;
            $this->tags[$tag . $this->tags[$tag . 'count']] = $this->indent_level;
        }
        $this->tags[$tag . $this->tags[$tag . 'count'] . 'parent'] = $this->tags['parent'];
        $this->tags['parent'] = $tag . $this->tags[$tag . 'count'];
    }

    private function retrieve_tag($tag)
    {
        if (!isset($this->tags[$tag . 'count'])) return;
        $temp_parent = $this->tags['parent'];
        while ($temp_parent) {
            if ($tag . $this->tags[$tag . 'count'] === $temp_parent) break;
            $temp_parent = isset($this->tags[$temp_parent . 'parent']) ? $this->tags[$temp_parent . 'parent'] : '';
        }
        if ($temp_parent) {
            $this->indent_level = $this->tags[$tag . $this->tags[$tag . 'count']];
            $this->tags['parent'] = $this->tags[$temp_parent . 'parent'];
        }
        unset($this->tags[$tag . $this->tags[$tag . 'count'] . 'parent']);
        unset($this->tags[$tag . $this->tags[$tag . 'count']]);
        if ($this->tags[$tag . 'count'] === 1) {
            unset($this->tags[$tag . 'count']);
        } else {
            $this->tags[$tag . 'count']--;
        }
    }

    private function indent_to_tag($tag)
    {
        if (!$this->tags[$tag . 'count']) return;
        $temp_parent = $this->tags['parent'];
        while ($temp_parent) {
            if ($tag . $this->tags[$tag . 'count'] === $temp_parent) break;
            $temp_parent = $this->tags[$temp_parent . 'parent'];
        }
        if ($temp_parent) $this->indent_level = $this->tags[$tag . $this->tags[$tag . 'count']];
    }

    private function get_tag($peek = false)
    {
        $input_char = '';
        $content = [];
        $comment = '';
        $space = false;
        $tag_start = null;
        $tag_end = null;
        $tag_start_char = false;
        $orig_pos = $this->pos;
        $orig_line_char_count = $this->line_char_count;
        do {
            if ($this->pos >= $this->input_length) {
                if ($peek) {
                    $this->pos = $orig_pos;
                    $this->line_char_count = $orig_line_char_count;
                }
                return count($content) ? implode('', $content) : ['', 'TK_EOF'];
            }
            $input_char = $this->input[$this->pos];
            $this->pos++;
            if (in_array($input_char, $this->whitespace)) {
                $space = true;
                continue;
            }
            if ($input_char === "'" || $input_char === '"') {
                $input_char .= $this->get_unformatted($input_char);
                $space = true;
            }
            if ($input_char === '=') $space = false;
            if (count($content) && $content[count($content) - 1] !== '=' && $input_char !== '>' && $space) {
                if ($this->line_char_count >= $this->options['wrap_line_length']) {
                    $this->print_newline(false, $content);
                    $this->print_indentation($content);
                } else {
                    $content[] = ' ';
                    $this->line_char_count++;
                }
                $space = false;
            }
            if ($input_char === '<' && !$tag_start_char) {
                $tag_start = $this->pos - 1;
                $tag_start_char = '<';
            }
            $this->line_char_count++;
            $content[] = $input_char;
            if (isset($content[1]) && $content[1] === '!') {
                $content = [$this->get_comment($tag_start)];
                break;
            }
        } while ($input_char !== '>');
        $tag_complete = implode('', $content);
        if (strpos($tag_complete, ' ') !== false) {
            $tag_index = strpos($tag_complete, ' ');
        } else {
            $tag_index = strpos($tag_complete, '>');
        }
        if ($tag_complete[0] === '<') {
            $tag_offset = 1;
        } else {
            $tag_offset = $tag_complete[2] === '#' ? 3 : 2;
        }
        $tag_check = strtolower(substr($tag_complete, $tag_offset, max($tag_index-$tag_offset, 0)));
        if ($tag_complete[strlen($tag_complete) - 2] === '/' ||
            in_array($tag_check, $this->single_token)) {
            if (!$peek) $this->tag_type = 'SINGLE';
        } else if ($tag_check === 'script') {
            if (!$peek) {
                $this->record_tag($tag_check);
                $this->tag_type = 'SCRIPT';
            }
        } else if ($tag_check === 'style') {
            if (!$peek) {
                $this->record_tag($tag_check);
                $this->tag_type = 'STYLE';
            }
        } else if ($this->is_unformatted($tag_check)) {
            $comment = $this->get_unformatted('</' . $tag_check . '>', $tag_complete);
            $content[] = $comment;
            if ($tag_start > 0 && in_array($this->input[$tag_start - 1], $this->whitespace)) {
                array_splice($content, 0, 0, $this->input[$tag_start - 1]);
            }
            $tag_end = $this->pos - 1;
            if (in_array($this->input[$tag_end + 1], $this->whitespace)) {
                $content[] = $this->input[$tag_end + 1];
            }
            $this->tag_type = 'SINGLE';
        } else if ($tag_check && $tag_check[0] === '!') {
            if (!$peek) {
                $this->tag_type = 'SINGLE';
                $this->traverse_whitespace();
            }
        } else if (!$peek) {
            if ($tag_check && $tag_check[0] === '/') {
                $this->retrieve_tag(substr($tag_check, 1));
                $this->tag_type = 'END';
                $this->traverse_whitespace();
            } else {
                $this->record_tag($tag_check);
                if (strtolower($tag_check) !== 'html') {
                    $this->indent_content = true;
                }
                $this->tag_type = 'START';
                $this->traverse_whitespace();
            }
            if (in_array($tag_check, $this->extra_liners)) {
                $this->print_newline(false, $this->output);
                if (count($this->output) && $this->output[count($this->output) - 2] !== "\n") {
                    $this->print_newline(true, $this->output);
                }
            }
        }
        if ($peek) {
            $this->pos = $orig_pos;
            $this->line_char_count = $orig_line_char_count;
        }
        return implode('', $content);
    }

    private function get_comment($start_pos)
    {
        $comment = '';
        $delimiter = '>';
        $matched = false;
        $this->pos = $start_pos;
        $input_char = $this->input[$this->pos];
        $this->pos++;
        while ($this->pos <= $this->input_length) {
            $comment .= $input_char;
            if ($comment[strlen($comment) - 1] === $delimiter[strlen($delimiter) - 1] &&
                strpos($comment, $delimiter) !== false) {
                break;
            }
            if (!$matched && strlen($comment) < 10) {
                if (strpos($comment, '<![if') === 0) {
                    $delimiter = '<![endif]>';
                    $matched = true;
                } else if (strpos($comment, '<![cdata[') === 0) {
                    $delimiter = ']]>';
                    $matched = true;
                } else if (strpos($comment, '<![') === 0) {
                    $delimiter = ']>';
                    $matched = true;
                } else if (strpos($comment, '<!--') === 0) {
                    $delimiter = '-->';
                    $matched = true;
                }
            }
            $input_char = $this->input[$this->pos];
            $this->pos++;
        }
        return $comment;
    }

    private function get_unformatted($delimiter, $orig_tag = false)
    {
        if ($orig_tag && strpos(strtolower($orig_tag), $delimiter) !== false) return '';
        $input_char = '';
        $content = '';
        $min_index = 0;
        $space = true;
        do {
            if ($this->pos >= $this->input_length) return $content;
            $input_char = $this->input[$this->pos];
            $this->pos++;
            if (in_array($input_char, $this->whitespace)) {
                if (!$space) {
                    $this->line_char_count--;
                    continue;
                }
                if ($input_char === "\n" || $input_char === "\r") {
                    $content .= "\n";
                    $this->line_char_count = 0;
                    continue;
                }
            }
            $content .= $input_char;
            $this->line_char_count++;
            $space = true;
            if (preg_match('/^data:image\/(bmp|gif|jpeg|png|svg\+xml|tiff|x-icon);base64$/', $content)) {
                $content .= substr($this->input, $this->pos, strpos($this->input, $delimiter, $this->pos) - $this->pos);
                $this->line_char_count = strpos($this->input, $delimiter, $this->pos) - $this->pos;
                $this->pos = strpos($this->input, $delimiter, $this->pos);
                continue;
            }
        } while ( strpos(strtolower($content), $delimiter, $min_index) === false);
        return $content;
    }

    private function get_token()
    {
        if ($this->last_token === 'TK_TAG_SCRIPT' || $this->last_token === 'TK_TAG_STYLE') {
            $type = substr($this->last_token, 7);
            $token = $this->get_contents_to($type);
            if (!is_string($token)) return $token;
            return [$token, 'TK_' . $type];
        }
        if ($this->current_mode === 'CONTENT') {
            $token = $this->get_content();
            if (!is_string($token)) return $token;
            else return [$token, 'TK_CONTENT'];
        }
        if ($this->current_mode === 'TAG') {
            $token = $this->get_tag();
            if (!is_string($token)) {
                return $token;
            } else {
                $tag_name_type = 'TK_TAG_' . $this->tag_type;
                return [$token, $tag_name_type];
            }
        }
    }

    private function get_full_indent($level)
    {
        $level = $this->indent_level + $level || 0;
        if ($level < 1) return '';
        return str_repeat($this->indent_string, $level);
    }

    private function is_unformatted($tag_check)
    {
        if (!in_array($tag_check, $this->options['unformatted'])) return false;
        if (strtolower($tag_check) !== 'a' || !in_array('a', $this->options['unformatted'])) return true;
        $next_tag = $this->get_tag(true);
        $matches = [];
        preg_match('/^\s*<\s*\/?([a-z]*)\s*[^>]*>\s*$/', ($next_tag ? $next_tag : ""), $matches);
        $tag = $matches ? $matches : null;
        return !$tag || in_array($tag, $this->options['unformatted']);
    }

    private function print_newline($force, &$arr)
    {
        $this->line_char_count = 0;
        if (!$arr || !count($arr)) return;
        if ($force || ($arr[count($arr) - 1] !== "\n")) $arr[] = "\n";
    }

    private function print_indentation(&$arr)
    {
        for ($i = 0; $i < $this->indent_level; $i++) {
            $arr[] = $this->indent_string;
            $this->line_char_count += strlen($this->indent_string);
        }
    }

    private function print_token($text)
    {
        if (($text || $text !== '') && count($this->output) && $this->output[count($this->output) - 1] === "\n") {
            $this->print_indentation($this->output);
            $text = ltrim($text);
        }
        $this->print_token_raw($text);
    }

    private function print_token_raw($text)
    {
        if ($text && $text !== '') {
            if (strlen($text) > 1 && $text[strlen($text) - 1] === "\n") {
                $this->output[] = substr($text, 0, -1);
                $this->print_newline(false, $this->output);
            } else {
                $this->output[] = $text;
            }
        }
        for ($n = 0; $n < $this->newlines; $n++) $this->print_newline($n > 0, $this->output);
        $this->newlines = 0;
    }

    private function indent()
    {
        $this->indent_level++;
    }

    private function unindent()
    {
        if ($this->indent_level > 0) $this->indent_level--;
    }

    public function beautify($input)
    {
        $this->input = $input;
        $this->input_length = strlen($this->input);
        $this->output = [];
        while (true) {
            $t = $this->get_token();
            $this->token_text = $t[0];
            $this->token_type = $t[1];
            if ($this->token_type === 'TK_EOF') break;
            switch ($this->token_type) {
                case 'TK_TAG_START':
                    $this->print_newline(false, $this->output);
                    $this->print_token($this->token_text);
                    if ($this->indent_content) {
                        $this->indent();
                        $this->indent_content = false;
                    }
                    $this->current_mode = 'CONTENT';
                    break;
                case 'TK_TAG_STYLE':
                case 'TK_TAG_SCRIPT':
                    $this->print_newline(false, $this->output);
                    $this->print_token($this->token_text);
                    $this->current_mode = 'CONTENT';
                    break;
                case 'TK_TAG_END':
                    if ($this->last_token === 'TK_CONTENT' && $this->last_text === '') {
                        $matches = [];
                        preg_match('/\w+/', $this->token_text, $matches);
                        $tag_name = isset($matches[0]) ? $matches[0] : null;
                        $tag_extracted_from_last_output = null;
                        if (count($this->output)) {
                            $matches = [];
                            preg_match('/(?:<|{{#)\s*(\w+)/', $this->output[count($this->output) - 1], $matches);
                            $tag_extracted_from_last_output = isset($matches[0]) ? $matches[0] : null;
                        }
                        if ($tag_extracted_from_last_output === null || $tag_extracted_from_last_output[1] !== $tag_name) {
                            $this->print_newline(false, $this->output);
                        }
                    }
                    $this->print_token($this->token_text);
                    $this->current_mode = 'CONTENT';
                    break;
                case 'TK_TAG_SINGLE':
                    $matches = [];
                    preg_match('/^\s*<([a-z]+)/i', $this->token_text, $matches);
                    $tag_check = $matches ? $matches : null;
                    if (!$tag_check || !in_array($tag_check[1], $this->options['unformatted'])) {
                        $this->print_newline(false, $this->output);
                    }
                    $this->print_token($this->token_text);
                    $this->current_mode = 'CONTENT';
                    break;
                case 'TK_CONTENT':
                    $this->print_token($this->token_text);
                    $this->current_mode = 'TAG';
                    break;
                case 'TK_STYLE':
                case 'TK_SCRIPT':
                    if ($this->token_text !== '') {
                        $this->print_newline(false, $this->output);
                        $text = $this->token_text;
                        $script_indent_level = 1;
                        if ($this->options['indent_scripts'] === "keep") {
                            $script_indent_level = 0;
                        } else if ($this->options['indent_scripts'] === "separate") {
                            $script_indent_level = -$this->indent_level;
                        }
                        $indentation = $this->get_full_indent($script_indent_level);
                        $matches = [];
                        preg_match('/^\s*/', $text, $matches);
                        $white = isset($matches[0]) ? $matches[0] : null;
                        $matches = [];
                        preg_match('/[^\n\r]*$/', $white, $matches);
                        $dummy = isset($matches[0]) ? $matches[0] : null;
                        $_level = count(explode($this->indent_string, $dummy)) - 1;
                        $reindent = $this->get_full_indent($script_indent_level - $_level);
                        $text = preg_replace('/^\s*/', $indentation, $text);
                        $text = preg_replace('/\r\n|\r|\n/', "\n" . $reindent, $text);
                        $text = preg_replace('/\s+$/', '', $text);
                        if ($text) {
                            $this->print_token_raw($indentation . trim($text));
                            $this->print_newline(false, $this->output);
                        }
                    }
                    $this->current_mode = 'TAG';
                    break;
            }
            $this->last_token = $this->token_type;
            $this->last_text = $this->token_text;
        }
        return implode('', $this->output);
    }
}
