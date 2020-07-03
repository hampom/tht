<?php

namespace o;

class OMap extends OBag {

    protected $type = 'map';

    protected $suggestMethod = [
        'count'   => 'length()',
        'size'    => 'length()',
        'empty'   => 'isEmpty()',
        'delete'  => 'remove()',
    ];

    public function jsonSerialize() {
        if (!count($this->val)) {
            return '{EMPTY_MAP}';
        }
        return $this->val;
    }

    static function create ($ary) {
        $m = new OMap ();
        $m->setVal($ary);
        // if (count($ary)) {
        //     $m->u_lock_keys(true);
        // }
        return $m;
    }

    function u_equals($otherMap) {
        $this->ARGS('*', func_get_args());
        if (!OMap::isa($otherMap)) { return false; }

        $otherMap = uv($otherMap);

        return uv($this) === $otherMap;
    }

    function u_clear() {
        $this->ARGS('', func_get_args());
        $this->val = OMap::create([]);
        return $this;
    }

    function u_copy($useReferences = false) {
        $this->ARGS('f', func_get_args());

        // php apparently copies the array when assigned to a new var
        $a = $this->val;

        if (!$useReferences) {
            foreach ($a as $k => $el) {
                if (OBag::isa($el)) {
                    $a[$k] = $el->u_copy(false);
                }
            }
        }

        return OMap::create($a);
    }

    function u_is_empty () {
        $this->ARGS('', func_get_args());
        return count(uv($this->val)) === 0;
    }

    function u_remove ($k) {
        $this->ARGS('s', func_get_args());
        $v = isset($this->val[$k]) ? $this->val[$k] : null;
        unset($this->val[$k]);
        if (is_null($v)) {
            if (isset($this->default)) {
                return $this->default;
            } else {
                return '';
            }
        }
        return $v;
    }

    function u_has_key ($key) {
        $this->ARGS('s', func_get_args());
        return isset($this->val[$key]);
    }

    function u_has_value ($value) {
        $this->ARGS('s', func_get_args());
        return array_search($value, $this->val, true) !== false;
    }

    function u_get_key ($value) {
        $this->ARGS('s', func_get_args());
        $found = array_search($value, $this->val, true);
        if ($found === false) {
            $v = v($value)->u_limit(20);
            $this->error("Map value not found: `$v`");
        }
    }

    function u_values () {
        $this->ARGS('', func_get_args());
        return OList::create(array_values($this->val));
    }

    function u_keys () {
        $this->ARGS('', func_get_args());
        return OList::create(array_keys($this->val));
    }

    function u_to_list () {
        $this->ARGS('', func_get_args());
        $out = [];
        foreach ($this->val as $k => $v) {
            $out []= $k;
            $out []= $v;
        }
        return OList::create($out);
    }

    function u_reverse () {
        $this->ARGS('', func_get_args());
        return OMap::create(array_flip($this->val));
    }

    function u_merge ($a2, $isSoft = false) {
        $this->ARGS('mf', func_get_args());
        if ($isSoft) {
            // Union + operator
            return OMap::create($this->val + $a2->val);
        } else {
            return OMap::create(array_merge($this->val, $a2->val));
        }
    }

    function u_slice ($keys) {
        $this->ARGS('l', func_get_args());
        $out = [];
        foreach ($keys as $k) {
            $out[$k] = $this[$k];
        }
        return OMap::create($out);
    }
}

