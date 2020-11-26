<?php
// searchsplitter.php -- HotCRP helper class for splitting search strings
// Copyright (c) 2006-2020 Eddie Kohler; see LICENSE.

class SearchSplitter {
    /** @var string */
    private $str;
    /** @var bool */
    private $utf8q;
    /** @var int */
    public $pos = 0;
    /** @var int */
    public $last_pos = 0;

    /** @param string $str */
    function __construct($str) {
        $this->str = $str;
        $this->utf8q = strpos($str, chr(0xE2)) !== false;
        $this->set_span_and_pos("");
    }
    /** @return bool */
    function is_empty() {
        return $this->str === "";
    }
    /** @return string */
    function rest() {
        return $this->str;
    }
    /** @return string */
    function shift_keyword() {
        if ($this->utf8q
            ? preg_match('/\A(?:[-_.a-zA-Z0-9]+|["“”][^"“”]+["“”]):/su', $this->str, $m)
            : preg_match('/\A(?:[-_.a-zA-Z0-9]+|"[^"]+"):/s', $this->str, $m)) {
            $this->set_span_and_pos($m[0]);
            return $this->utf8q ? preg_replace('/[“”]/u', '"', $m[0]) : $m[0];
        } else {
            return "";
        }
    }
    /** @param string $exceptions
     * @return string */
    function shift($exceptions = null) {
        if ($exceptions === null) {
            $exceptions = '\(\)\[\]';
        } else if ($exceptions !== "()" && $exceptions !== "") {
            $exceptions = preg_quote($exceptions);
        }
        if ($this->utf8q
            ? preg_match("/\\A(?:[\"“”][^\"“”]*(?:[\"“”]|\\z)|[^\"“”\\s{$exceptions}]*)*/su", $this->str, $m)
            : preg_match("/\\A(?:\"[^\"]*(?:\"|\\z)|[^\"\\s{$exceptions}]*)*/s", $this->str, $m)) {
            $this->set_span_and_pos($m[0]);
            return $this->utf8q ? preg_replace('/[“”]/u', '"', $m[0]) : $m[0];
        } else {
            $this->last_pos = $this->pos = $this->pos + strlen($this->str);
            $this->str = "";
            return "";
        }
    }
    /** @param string $str */
    function shift_past($str) {
        assert(str_starts_with($this->str, $str));
        $this->set_span_and_pos($str);
    }
    /** @return bool */
    function skip_whitespace() {
        $this->set_span_and_pos("");
        return $this->str !== "";
    }
    /** @return string */
    function shift_balanced_parens() {
        $result = substr($this->str, 0, self::span_balanced_parens($this->str));
        $this->set_span_and_pos($result);
        return $result;
    }
    /** @param string $s
     * @return list<string> */
    static function split_balanced_parens($s) {
        $splitter = new SearchSplitter($s);
        $w = [];
        while ($splitter->skip_whitespace()) {
            $w[] = $splitter->shift_balanced_parens();
        }
        return $w;
    }
    /** @param string $re
     * @param list<string> &$m @phan-output-reference */
    function match($re, &$m = null) {
        return preg_match($re, $this->str, $m);
    }
    /** @param string $substr */
    function starts_with($substr) {
        return str_starts_with($this->str, $substr);
    }
    private function set_span_and_pos($prefix) {
        $this->last_pos = $this->pos + strlen($prefix);
        $next = substr($this->str, strlen($prefix));
        if ($this->utf8q) {
            $next = preg_replace('/\A\s+/u', "", $next);
        } else {
            $next = ltrim($next);
        }
        $this->pos += strlen($this->str) - strlen($next);
        $this->str = $next;
    }
    /** @param string $str
     * @param int $pos
     * @param ?callable(string):bool $endf
     * @return int */
    static function span_balanced_parens($str, $pos = 0, $endf = null) {
        $pstack = "";
        $plast = "";
        $quote = 0;
        $len = strlen($str);
        while ($pos < $len) {
            $ch = $str[$pos];
            // stop when done
            if ($plast === ""
                && !$quote
                && ($endf === null ? ctype_space($ch) : call_user_func($endf, $ch, $pos))) {
                break;
            }
            // translate “” -> "
            if ($ch === "\xE2"
                && $pos + 2 < $len
                && $str[$pos + 1] === "\x80"
                && (ord($str[$pos + 2]) & 0xFE) === 0x9C) {
                $ch = "\"";
                $pos += 2;
            }
            if ($quote) {
                if ($ch === "\\" && $pos + 1 < strlen($str)) {
                    ++$pos;
                } else if ($ch === "\"") {
                    $quote = 0;
                }
            } else if ($ch === "(") {
                $pstack .= $plast;
                $plast = ")";
            } else if ($ch === "[") {
                $pstack .= $plast;
                $plast = "]";
            } else if ($ch === "{") {
                $pstack .= $plast;
                $plast = "}";
            } else if ($ch === ")" || $ch === "]" || $ch === "}") {
                do {
                    $pcleared = $plast;
                    $plast = (string) substr($pstack, -1);
                    $pstack = (string) substr($pstack, 0, -1);
                } while ($ch !== $pcleared && $pcleared !== "");
                if ($pcleared === "") {
                    break;
                }
            } else if ($ch === "\"") {
                $quote = 1;
            }
            ++$pos;
        }
        return $pos;
    }
}
