<?php

namespace o;

abstract class OTypeString extends OVar {

    protected $type = 'typeString';

    protected $suggestMethod = [
        'tostring'   => 'stringify()',
    ];

    protected $str = '';
    protected $stringType = 'text';
    protected $bindParams = [];
    protected $overrideParams = [];
    protected $appendedTypeStrings = [];

    function __construct ($s) {
        if (OTypeString::isa($s)) {
            $s = $s->getString();
        }
        $this->str = $s;
    }

    function __toString() {
        $maxLen = 30;
        $len = strlen($this->str);
        $s = substr(trim($this->str), 0, $maxLen);
        if ($len > $maxLen) {
            $s .= '…';
        }
        $s = preg_replace('/\n+/', ' ', $s);

        // This format is recognized by the Json formatter
        $c = preg_replace('/.*\\\\/', '', get_class($this));
        return "<<<$c: $s>>>";
    }

    function jsonSerialize() {
        return $this->__toString();
    }

    static function concat($a, $b) {
        return $a->appendTypeString($b);
    }

    static function staticError($msg) {
        ErrorHandler::addOrigin('typeString');
        Tht::error($msg);
    }

    static function create ($type, $s) {
        $nsClassName = '\\o\\' . ucfirst($type) . 'TypeString';
        if (!class_exists($nsClassName)) {
            self::staticError("TypeString of type `$nsClassName` not supported.");
        }
        return new $nsClassName ($s);
    }

    static function getUntyped ($s, $type) {
        if (!OTypeString::isa($s)) {
            $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'];
            self::staticError("`$caller` must be passed a TypeString.  Try: `$type'...'`");
        }
        return self::_getUntyped($s, $type, false);
    }

    static function getUntypedRaw ($s, $type) {
        if (!OTypeString::isa($s)) {
            $caller = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS, 2)[1]['function'];
            self::staticError("`$caller` must be passed a TypeString.  Try: `$type'...'`");
        }
        return self::_getUntyped($s, $type, true);
    }

    private static function _getUntyped ($s, $type, $getRaw) {
        if ($type && $s->stringType !== $type) {
            self::staticError("TypeString must be of type `$type`. Got: `$s->stringType`");
        }
        return $getRaw ? $s->u_raw_string() : $s->u_stringify();
    }

    static function getUntypedNoError ($s) {
        if (!OTypeString::isa($s)) {
            return $s;
        }
        return $s->u_raw_string();
    }

    private function escapeParams() {

        $escParams = [];
        foreach($this->bindParams as $k => $v) {
            if (OTypeString::isa($v)) {
                $plain = $v->u_stringify();
                if ($v->u_string_type() === $this->u_string_type()) {
                    // If same lock type, don't escape
                    $escParams[$k] = $plain;
                } else {
                    $escParams[$k] = $this->u_z_escape_param($plain);
                }
            }
            else if ($this->overrideParams) {
                if (isset($this->overrideParams[$k])) {
                    $this->error("Must provide an update value for key `$k`.");
                }
                $escParams[$k] = $this->overrideParams[$k];
            }
            else {
                $escParams[$k] = $this->u_z_escape_param($v);
            }
        }
        $escParams = OMap::isa($this->bindParams)
            ? OMap::create($escParams)
            : OList::create($escParams);

        return $escParams;
    }

    function appendTypeString($l) {
        $t1 = $this->u_string_type();
        $t2 = $l->u_string_type();
        if ($t1 !== $t2) {
            $this->error("Can only append TypeStrings of the same type. Got: `$t1` and `$t2`");
        }
        $this->str .= $l->u_raw_string();
        return $this;
    }

    // override
    protected function u_z_escape_param($k) {
        return $k;
    }

    function u_stringify () {

        $this->ARGS('', func_get_args());
        $out = $this->str;

        if (count($this->bindParams)) {
            $escParams = $this->escapeParams();
            $out = v($this->str)->u_fill($escParams);
        }

        if (count($this->appendedTypeStrings)) {
            $num = 0;
            foreach ($this->appendedTypeStrings as $s) {
                $us = $s->u_stringify();
                $out = str_replace("[LOCK_STRING_$num]", $us, $out);
                $num += 1;
            }
        }

        return $out;
    }

    function u_fill ($params) {
        if (!OList::isa($params) && !OMap::isa($params)) {
            $params = OList::create(func_get_args());
        }
        $this->bindParams = $params;
        return $this;
    }

    function u_raw_string () {
        $this->ARGS('', func_get_args());
        return $this->str;
    }

    function u_params () {
        $this->ARGS('', func_get_args());
        return $this->bindParams;
    }

    function u_string_type() {
        $this->ARGS('', func_get_args());
        return $this->stringType;
    }

    // Allow user to provide pre-escaped params
    function u_x_danger_override_params($overrideParams=[]) {
        $this->overrideParams = $overrideParams;
    }
}

