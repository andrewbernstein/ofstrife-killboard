<?php
/**
 * Generate and parse killboard URIs
 * @package EDK
 */
class edkURI {
	/** @var string URI for killboard */
	private static $kb_host = null;

	/** @var boolean Whether to use pathinfo URLs */
	private static $pathinfo = false;

	/** @var array Cached arguments generated by parseURI */
	private static $args = null;

	/**
	 * Parse the current URI and return the EDK arguments.
	 *
	 * This returns an ordered array of parameter arrays. Each parameter array
	 * contains name, value (or true if no value), true/false depending on
	 * whether it was in the pathinfo or querystring.
	 *
	 * The first two components from path info will be labelled 'a' and 'id'.
	 *
	 * pathinfo is treated as kburl/index/page(a)/id/name/name
	 * e.g.
	 * http://killboard/path/to/kb/index/kill_detail/45/unlimited
	 * returns {0=>(a, kill_detail, true), 1=>(id, 45, true), 2=>(unlimited,true, true)
	 * http://killboard/path/to/kb/index/kill_detail/?id=45&unlimited
	 * returns {0=>(a, kill_detail, true), 1=>(id, 45, false), 2=>(unlimited, true, true)
	 * http://killboard/path/to/kb/?a=kill_detail&id=45&unlimited
	 * returns {0=>(a, kill_detail, false), 1=>(id, 45, false), 2=>(unlimited, true, true)
	 *
	 * @return array
	 */
	public static function parseURI()
	{
		if (isset(self::$args)) {
			return self::$args;
		}
		$args = array();
		$pagefound = false;
		$pathinfo = null;

		if(isset($_SERVER['PATH_INFO'])) {
			$pathinfo = trim($_SERVER['PATH_INFO'], '/');
		}
		if ($pathinfo) {
			$pathparts = explode('/', $pathinfo);
			$pos = 0;
			foreach($pathparts as $parameter) {
				if ($parameter == '' || is_null($parameter)) {
					continue;
				} else if ($pos == 0) {
					if (is_numeric($parameter)) {
						$args[] = array('a', 'home', true);
						$args[] = array($parameter, true, true);
						$pos++;
					} else {
						$args[] = array('a', $parameter, true);
					}
					$pagefound = true;
				} else {
					$args[] = array($parameter, true, true);
				}
				$pos++;
			}
		}
		foreach(explode('&', $_SERVER['QUERY_STRING']) as $parameter) {
			if ($parameter == ''){
				continue;
			}
			$parts = explode('=', $parameter);
			if ($parts[0] == 'a') {
				if (!$parts[1]) {
					continue;
				} else if ($pathinfo) {
					// An old broken link. Discard the pathinfo and redirect.
					header('Location: '.KB_HOST.'/?'.$_SERVER['QUERY_STRING']);
					die;
				} else {
					$pagefound = true;
					array_unshift($args, array('a', $parts[1], false));
				}
			} else {
				if (isset($parts[1])) {
					$args[] = array($parts[0], $parts[1], false);
				} else {
					$args[] = array($parts[0], true, false);
				}
			}
		}
		if (!$pagefound) {
			array_unshift($args, array('a', 'home', false));
		}
		if ($args[0][1] == 'index') {
			$args[0][1] = 'home';
		}

		self::$args = $args;
		return $args;
	}

	/**
	 * Fetch the value for an argument in the URI.
	 *
	 * Returns false if the argument was not present. If the position argument
	 * is given the name will be searched for first then the position if there
	 * is no match on name.
	 *
	 * e.g.
	 * kburl/?a=kill_detail&id=152752&unlimited
	 * edkURI::getArg('unlimited', 2) returns true
	 *
	 * kburl/index/kill_detail/152752/unlimited
	 * edkURI::getArg('unlimited', 2) returns true
	 *
	 * kburl/?a=kill_detail&id=152752&unlimited
	 * edkURI::getArg('id', 1) returns 152752
	 *
	 * kburl/index/kill_detail/152752/unlimited
	 * edkURI::getArg('id', 1) returns 152752
	 *
	 * @param string $name
	 * @param integer $position
	 * @return string|boolean The value of the argument or false if not present.
	 */
	public static function getArg($name, $position = null)
	{
		if (is_null(self::$args)) {
			self::parseURI();
		}
		foreach(self::$args as $arg) {
			if ($arg[0] == $name) {
				return $arg[1];
			}
		}
		if (!is_null($position) && isset(self::$args[$position])) {
			if (self::$args[$position][1] === true) {
				return self::$args[$position][0];
			}
		}
		return false;
	}

	/**
	 * Create a board URI from the given arguments.
	 *
	 * This takes an ordered array of parameter arrays. Each parameter array
	 * contains name, value (or true if no value), true/false depending on
	 * whether it was in the pathinfo or querystring. If a page is not specified
	 * then the current page will be assumed.
	 *
	 * e.g.
	 * 0=>(a, kill_detail, true), 1=>(id, 45, true), 2=>(unlimited,true, true)
	 *
	 * If path URIs are enabled this returns:
	 * kburl/index/kill_detail/45/unlimited/
	 *
	 * If path URIs are disabled this returns:
	 * kburl/?a=kill_detail&id=45&unlimited
	 *
	 * Passing in no arguments would return:
	 * kburl/index/home/
	 * or kburl/?a=home
	 *
	 * @param array $parameters
	 * @return string valid URI to an EDK page.
	 */
	private static function make($parameters)
	{
		if(is_null(self::$kb_host)) {
			if (defined('KB_HOST')) {
				self::$kb_host = KB_HOST."/";
				if (self::$pathinfo) {
					self::$kb_host .= "index.php/";
				}
			} else if(class_exists('Config', true)) {
				self::$kb_host = Config::get('cfg_kbhost')."/";
				if (self::$pathinfo) {
					self::$kb_host .= "index.php/";
				}
			} else {
				self::$kb_host = $_SERVER['HTTP_HOST'].$_SERVER['SCRIPT_NAME'];
				if (self::$pathinfo) {
					self::$kb_host .= "/";
				}
			}
		}
		// Let's be nice and accept a single argument to not be nested.
		if (!is_array($parameters[0])) {
			$parameters = array($parameters);
		}
		$parameters[] = array("akey", session::makeKey(), false);
		$url = self::$kb_host;
		$patharr = array();
		$qryarr = array();
		foreach($parameters as $param) {
			if ($param[2] && self::$pathinfo) {
				if ($param[1] === true) {
					$patharr[] = $param[0];
				} else {
					$patharr[] = $param[1];
				}
			} else {
				$qryarr[] = $param[0].'='.$param[1];
			}
		}

		if (self::$pathinfo) {
			// If no page is specified then use the current page
			if (!$parameters || $parameters[0][0] != 'a') {
				$url .= self::getArg('a', 0).'/';
			}
			if($patharr) {
				$url .= join('/', $patharr).'/';
			}
			if ($qryarr) {
				$url .= '?';
			}
		} else  {
			// If no page is specified then use the current page
			if (!$qryarr) {
				$url .= '?a='.self::getArg('a', 0);
			} else if ($parameters[0][0] != 'a') {
				$url .= '?a='.self::getArg('a', 0)."&";
			} else {
				$url .= '?';
			}
		}
		$url .= join('&amp;', $qryarr);
		return $url;
	}

	/**
	 * Create a board URI from the given arguments.
	 *
	 * This takes an ordered array of parameter arrays. Each parameter array
	 * contains name, value (or true if no value), true/false depending on
	 * whether it was in the pathinfo or querystring. If a page is not specified
	 * then the current page will be assumed.
	 *
	 * e.g.
	 * build({0=>{'all_id', 1234, true}}, {'view', 'kills', true})
	 * or
	 * build({'all_id', 1234, true}, {'view', 'kills', true})
	 *
	 * @param array $parameters an array of parameters or a single parameter
	 * @param array $arr a parameter.
	 * @return string Valid URI build from the arguments given.
	 */
	public static function build($parameters, $arr = null)
	{
		$args = func_get_args();
		if (is_array($args[0][0])) {
			if (isset($args[1])) {
				return self::make(array_merge(array_shift($args), $args));
			} else {
				return self::make($args[0]);
			}
		} else {
			return self::make($args);
		}
	}

	/**
	 * Make a link to a specific page and id combination
	 *
	 * e.g.
	 * <code>
	 * edkURI::page('alliance_detail', 1234, 'all_id')
	 * </code>
	 * returns a link to kburl/alliance_detail/1234/ or
	 * kburl/?a=alliance_detail&all_id=1234
	 *
	 * <code>
	 * edkURI::page('awards')
	 * </code>
	 * returns a link to kburl/awards/ or
	 * kburl/?a=awards
	 *
	 * <code>
	 * edkURI::page()
	 * returns a link to kburl/
	 * </code>
	 * 
	 * @param string $page the name of the page to link to
	 * @param integer $id an optional id to use
	 * @param string $idname an optional name for the id
	 * @return string The URI for the page.
	 */
	public static function page($page = null, $id = 0, $idname = 'id')
	{
		$id = htmlentities($id);

		if (is_null($page)) {
			return self::$kb_host;
		} else if ($id) {
			return self::build(array('a', $page, true),
					array($idname, $id, true));
		} else {
			return self::build(array('a', $page, true));
		}
	}
	/**
	 * Set the root url used to create URIs.
	 *
	 * @param string $host
	 */
	public static function setRoot($host)
	{
		self::$kb_host = $host;
	}
	/**
	 * Set whether to use the path info to retrieve the page.
	 *
	 * e.g.
	 * true:
	 * kburl/index.php/kill_detail/1234
	 *
	 * false:
	 * kburl/?a=kill_detail&id=1234
	 *
	 * @param boolean $pathinfo Set true to make pathinfo based URLS
	 */
	public static function usePath($pathinfo = false)
	{
		self::$pathinfo = !!$pathinfo;
	}
}
