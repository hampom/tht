<?php

namespace o;

class u_Session extends OStdModule {

    private $sessionStarted = false;
    private $flashKey = '**flash';
    private $flashData = [];
    public $sessionIdName = 'sid';

    public function startSession() {

        if ($this->sessionStarted) {
            return;
        }
        $this->sessionStarted = true;

        if (headers_sent()) {
            Tht::error('Session can not be started after page content is sent.');
        }

        Tht::module('Perf')->u_start('Session.start');

        Security::initSessionParams();

        session_save_path(Tht::path('sessions'));

        session_name($this->sessionIdName);
        session_start();

        if (isset($_SESSION[$this->flashKey])) {
            $this->flashData = $_SESSION[$this->flashKey];
            unset($_SESSION[$this->flashKey]);
        }

        Tht::module('Perf')->u_stop();
    }

    function u_set($keyOrMap, $value=null) {

        $this->startSession();

        if (is_string($keyOrMap)) {
            if (is_null($value)) {
                Tht::error('Session.set() missing 2nd `value` argument.');
            }
            $_SESSION[$keyOrMap] = $this->wrapVal($value);
        }
        else {
            foreach($keyOrMap as $k => $v) {
                $_SESSION[$k] = $this->wrapVal($v);
            }
        }
    }

    function wrapVal($val) {

        return Tht::module('Json')->u_encode(OMap::create(['v' => $val]));
    }

    function unwrapVal($val) {

        return Tht::module('Json')->u_decode($val)['v'];
    }

    function u_get($key, $default=null) {

        $this->ARGS('s*', func_get_args());

        $this->startSession();
        if (!isset($_SESSION[$key])) {
            if (is_null($default)) {
                Tht::error('Unknown session key: `' . $key . '`');
            }
            return $default;
        }
        else {
            return $this->unwrapVal($_SESSION[$key]);
        }
    }

    function u_get_all() {

        $this->ARGS('', func_get_args());

        $this->startSession();
        $all = $_SESSION;
        unset($all[$this->flashKey]);

        return OMap::create($all);
    }

    function u_delete($key) {

        $this->ARGS('s', func_get_args());

        $this->startSession();
        if (isset($_SESSION[$key])) {
            $val = $this->unwrapVal($_SESSION[$key]);
            unset($_SESSION[$key]);
            return $val;
        }

        return '';
    }

    function u_delete_all() {

        $this->ARGS('', func_get_args());

        $this->startSession();
        $_SESSION = [];
    }

    function u_has_key($key) {

        $this->ARGS('s', func_get_args());

        $this->startSession();

        return isset($_SESSION[$key]);
    }

    function u_add_counter($key) {

        $this->ARGS('s', func_get_args());

        $this->startSession();
        if (!isset($_SESSION[$key])) {
            $_SESSION[$key] = 0;
        }
        $_SESSION[$key] += 1;

        return $_SESSION[$key];
    }

    function u_add_to_list($key, $value) {

        $this->ARGS('s*', func_get_args());

        $this->startSession();
        if (!isset($_SESSION[$key])) {
            $list = OList::create([]);
        }
        else {
            $list = $this->unwrapVal($_SESSION[$key]);
        }

        $list []= $value;
        $_SESSION[$key] = $this->wrapVal($list);

        return OList::create($list);
    }

    function u_get_flash($key, $default='') {

        $this->ARGS('s*', func_get_args());

        $this->startSession();
        if (isset($this->flashData[$key])) {
            return $this->flashData[$key];
        }

        return $default;
    }

    function u_set_flash($key, $value) {

        $this->ARGS('s*', func_get_args());

        $this->startSession();
        if (!isset($_SESSION[$this->flashKey])) {
            $_SESSION[$this->flashKey] = [];
        }

        $_SESSION[$this->flashKey][$key] = $value;

        return EMPTY_RETURN;
    }

    function u_has_flash($key) {

        $this->ARGS('s', func_get_args());

        $this->startSession();

        return isset($this->flashData[$key]);
    }

    function u_repeat_flash() {

        $this->ARGS('', func_get_args());

        $this->startSession();

        $_SESSION[$this->flashKey] = $this->flashData;

        return EMPTY_RETURN;
    }
}


