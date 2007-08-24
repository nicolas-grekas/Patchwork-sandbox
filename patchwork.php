<?php /*********************************************************************
 *
 *   Copyright : (C) 2007 Nicolas Grekas. All rights reserved.
 *   Email     : p@tchwork.com
 *   License   : http://www.gnu.org/licenses/agpl.txt GNU/AGPL, see AGPL
 *
 *   This program is free software; you can redistribute it and/or modify it
 *   under the terms of the GNU Affero General Public License as published
 *   by the Free Software Foundation; either version 3 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/


define('patchwork', microtime(true));
error_reporting(E_ALL | E_STRICT);

// IIS compatibility
isset($_SERVER['REQUEST_URI']) || $_SERVER['REQUEST_URI'] = $_SERVER['URL'];
isset($_SERVER['SERVER_ADDR']) || $_SERVER['SERVER_ADDR'] = '127.0.0.1';

isset($_GET['exit$']) && die('Exit requested');

// Convert ISO-8859-1 URLs to UTF-8 ones
if (!preg_match('//u', urldecode($a = $_SERVER['REQUEST_URI'])))
{
	$a = $a != utf8_decode($a) ? '/' : preg_replace("'(?:%[89a-f][0-9a-f])+'ei", "urlencode(utf8_encode(urldecode('$0')))", $a);
	$b = $_SERVER['REQUEST_METHOD'];

	if ('GET' == $b || 'HEAD' == $b)
	{
		header('HTTP/1.1 301 Moved Permanently');
		header('Location: ' . $a);
		exit;
	}
	else
	{
		$_SERVER['REQUEST_URI'] = $a;
		$b = strpos($a, '?');
		$_SERVER['QUERY_STRING'] = false !== $b++ && $b < strlen($a) ? substr($a, $b) : '';
		parse_str($_SERVER['QUERY_STRING'], $_GET);
	}
}

// {{{ registerAutoloadPrefix()
$patchwork_autoload_prefix = array();

function registerAutoloadPrefix($class_prefix, $class_to_file_callback)
{
	if ($len = strlen($class_prefix))
	{
		$registry =& $GLOBALS['patchwork_autoload_prefix'];
		$class_prefix = strtolower($class_prefix);
		$i = 0;

		do
		{
			$c = ord($class_prefix[$i]);
			isset($registry[$c]) || $registry[$c] = array();
			$registry =& $registry[$c];
		}
		while (++$i < $len);

		$registry[-1] = $class_to_file_callback;
	}
}
// }}}

// {{{ hunter: a user callback is called when a hunter object is destroyed
class hunter
{
	protected

	$callback,
	$param_arr;

	function __construct($callback, $param_arr = array())
	{
		$this->callback =& $callback;
		$this->param_arr =& $param_arr;
	}

	function __destruct()
	{
		call_user_func_array($this->callback, $this->param_arr);
	}
}
// }}}

// {{{ ob: wrapper for ob_start
class ob
{
	static $in_handler = 0;

	static function start($callback = null, $chunk_size = null, $erase = true)
	{
		null !== $callback && $callback = array(new ob($callback), 'callback');
		return ob_start($callback, $chunk_size, $erase);
	}

	protected function __construct($callback)
	{
		$this->callback = $callback;
	}

	function &callback(&$buffer, $mode)
	{
		$a = self::$in_handler++;
		$buffer = call_user_func_array($this->callback, array(&$buffer, $mode));
		self::$in_handler = $a;
		return $buffer;
	}
}
// }}}

// {{{ Load configuration

$_REQUEST = array(); // $_REQUEST is an open door to security problems.
$CONFIG = array();
$patchwork_appId = './.config.patchwork.php';

define('__patchwork__', dirname(__FILE__));
define('IS_WINDOWS', '\\' == DIRECTORY_SEPARATOR);
define('PATCHWORK_PROJECT_PATH', getcwd());

# From http://www.w3.org/International/questions/qa-forms-utf-8
define('UTF8_VALID_RX', '/(?:[\x00-\x7F]|[\xC2-\xDF][\x80-\xBF]|\xE0[\xA0-\xBF][\x80-\xBF]|[\xE1-\xEC\xEE\xEF][\x80-\xBF]{2}|\xED[\x80-\x9F][\x80-\xBF]|\xF0[\x90-\xBF][\x80-\xBF]{2}|[\xF1-\xF3][\x80-\xBF]{3}|\xF4[\x80-\x8F][\x80-\xBF]{2})+/');

// Load the configuration
require file_exists($patchwork_appId) ? $patchwork_appId : (__patchwork__ . '/c3mro.php');

// Restore the current dir in shutdown context.
function patchwork_chdir($realdir) {$realdir === getcwd() || chdir($realdir);}
register_shutdown_function('patchwork_chdir', PATCHWORK_PROJECT_PATH);
// }}}

// {{{ Global Initialisation
isset($CONFIG['umask']) && umask($CONFIG['umask']);
define('DEBUG', $CONFIG['debug.allowed'] && (!$CONFIG['debug.password'] || (isset($_COOKIE['debug.password']) && $CONFIG['debug.password'] == $_COOKIE['debug.password'])) ? 1 : 0);
$CONFIG['maxage'] = isset($CONFIG['maxage']) ? $CONFIG['maxage'] : 2678400;
define('IS_POSTING', 'POST' == $_SERVER['REQUEST_METHOD']);
define('PATCHWORK_DIRECT',  '_' == $_SERVER['PATCHWORK_REQUEST']);
define('TURBO', !DEBUG && isset($CONFIG['turbo']) && $CONFIG['turbo']);

function E($msg = '__getDeltaMicrotime')
{
	return class_exists('patchwork', false) ? patchwork::log($msg, false, false) : W($msg, E_USER_NOTICE);
}

function W($msg, $err = E_USER_WARNING)
{
	ini_set('log_errors', true);
	ini_set('error_log', './error.log');
	ini_set('display_errors', false);
	trigger_error($msg, $err);
}
// }}}


{ // <-- Hack to enable the next functions only when execution reaches this point

// include with sandboxed namespace
function patchwork_include($file) {return include $file;}

// {{{ function resolvePath(): patchwork-specific include_path-like mechanism
function resolvePath($file, $level = false, $base = false)
{
	$last_patchwork_paths = count($GLOBALS['patchwork_paths']) - 1;

	if (false === $level)
	{
		$i = 0;
		$level = $last_patchwork_paths;
	}
	else
	{
		0 <= $level && $base = 0;
		$i = $last_patchwork_paths - $level - $base;
		0 > $i && $i = 0;
	}

	global $patchwork_lastpath_level;
	$patchwork_lastpath_level = $level;


	if (0 == $i)
	{
		$source = PATCHWORK_PROJECT_PATH .'/'. $file;
		if (IS_WINDOWS ? win_file_exists($source) : file_exists($source)) return $source;
	}


	$file = strtr($file, '\\', '/');
	if ($last_patchwork_paths = '/' == substr($file, -1)) $file = substr($file, 0, -1);

	if (DBA_HANDLER)
	{
		static $db;
		isset($db) || $db = dba_popen('./.parentPaths.db', 'rd', DBA_HANDLER);
		$base = dba_fetch($file, $db);
	}
	else
	{
		$base = md5($file);
		$base = PATCHWORK_ZCACHE . $base[0] . '/' . $base[1] . '/' . substr($base, 2) . '.path.txt';
		$base = @file_get_contents($base);
	}

	if (false !== $base)
	{
		$base = explode(',', $base);
		do if (current($base) >= $i)
		{
			$base = (int) current($base);
			$level = $patchwork_lastpath_level -= $base - $i;
			
			return $GLOBALS['patchwork_include_paths'][$base] . '/' . (0<=$level ? $file : substr($file, 6)) . ($last_patchwork_paths ? '/' : '');
		}
		while (false !== next($base));
	}

	$patchwork_lastpath_level = -PATCHWORK_PATH_OFFSET;

	return false;
}
// }}}

// {{{ function patchworkProcessedPath(): automatically added by the preprocessor in files in the include_path
function patchworkProcessedPath($file)
{
	$file = strtr($file, '\\', '/');
	$f = '.' . $file . '/';

	if (false !== strpos($f, './') || false !== strpos($file, ':'))
	{
		$f = realpath($file);
		if (!$f) return $file;

		$file = false;
		$i = count($GLOBALS['patchwork_paths']);
		$p =& $GLOBALS['patchwork_include_paths'];
		$len = count($p);

		for (; $i < $len; ++$i)
		{
			if (substr($f, 0, strlen($p[$i])+1) == $p[$i] . DIRECTORY_SEPARATOR)
			{
				$file = substr($f, strlen($p[$i])+1);
				break;
			}
		}

		if (false === $file) return $f;
	}

	$file = 'class/' . $file;

	$source = resolvePath($file);

	if (false === $source) return false;

	$level = $GLOBALS['patchwork_lastpath_level'];

	$file = strtr($file, '\\', '/');
	$cache = ((int)(bool)DEBUG) . (0>$level ? -$level .'-' : $level);
	$cache = './.'. strtr(str_replace('_', '%2', str_replace('%', '%1', $file)), '/', '_') . '.' . $cache . '.' . PATCHWORK_PATH_TOKEN . '.zcache.php';

	if (file_exists($cache) && (TURBO || filemtime($cache) > filemtime($source))) return $cache;

	patchwork_preprocessor::run($source, $cache, $level, false);

	return $cache;
}
// }}}

// {{{ function __autoload()
function __autoload($searched_class)
{
	$a = strtolower($searched_class);

	if ($a =& $GLOBALS['patchwork_autoload_cache'][$a] && TURBO)
	{
		if (is_int($a))
		{
			$b = $a;
			unset($a);
			$a = $b - PATCHWORK_PATH_OFFSET;

			$b = $searched_class;
			$i = strrpos($b, '__');
			false !== $i && '__' === rtrim(strtr(substr($b, $i), ' 0123456789', '#          ')) && $b = substr($b, 0, $i);

			$a = $b . '.php.' . ((string)(int)(bool)DEBUG) . (0>$a ? -$a . '-' : $a);
		}

		$a = './.class_' . $a . '.' . PATCHWORK_PATH_TOKEN . '.zcache.php';

		if (file_exists($a))
		{
			patchwork_include($a);

			if (class_exists($searched_class, false)) return;
		}

		$GLOBALS['a' . PATCHWORK_PATH_TOKEN] = false;
	}

	static $load_autoload = true;

	if ($load_autoload)
	{
		require __patchwork__ . '/autoload.php';
		$load_autoload = false;
	}


	patchwork_autoload($searched_class);
}
// }}}

function patchwork_is_a($obj, $class)
{
	return $obj instanceof $class;
}

}

// {{{ file_exists replacement on Windows
// Fix a bug with long file names.
// In debug mode, checks if character case is strict.
if (DEBUG || PHP_VERSION < '5.2')
{
	if (DEBUG)
	{
		function win_file_exists($file)
		{
			if (file_exists($file) && $realfile = realpath($file))
			{
				$file = strtr($file, '/', '\\');

				$i = strlen($file);
				$j = strlen($realfile);

				while ($i-- && $j--)
				{
					if ($file[$i] != $realfile[$j])
					{
						if (strtolower($file[$i]) == strtolower($realfile[$j]) && !(0 == $i && ':' == substr($file, 1, 1))) W("Character case mismatch between requested file and its real path ({$file} vs {$realfile})");
						break;
					}
				}

				return true;
			}
			else return false;
		}
	}
	else
	{
		function win_file_exists($file) {return file_exists($file) && (strlen($file) < 100 || realpath($file));}
	}

	function win_is_file($file)       {return win_file_exists($file) && is_file($file);}
	function win_is_dir($file)        {return win_file_exists($file) && is_dir($file);}
	function win_is_link($file)       {return win_file_exists($file) && is_link($file);}
	function win_is_executable($file) {return win_file_exists($file) && is_executable($file);}
	function win_is_readable($file)   {return win_file_exists($file) && is_readable($file);}
	function win_is_writable($file)   {return win_file_exists($file) && is_writable($file);}
}
else
{
	function win_file_exists($file) {return file_exists($file);}
}
//}}}


defined('PATCHWORK_SETUP') && patchwork_setup::call();

// {{{ Debug context
DEBUG && patchwork_debug::call();
// }}}

// {{{ Validator
$a = isset($_SERVER['HTTP_IF_NONE_MATCH'])
	? $_SERVER['HTTP_IF_NONE_MATCH']
	: isset($_SERVER['HTTP_IF_MODIFIED_SINCE']);

if ($a)
{
	if (true === $a)
	{
		// Patch an IE<=6 bug when using ETag + compression
		$a = explode(';', $_SERVER['HTTP_IF_MODIFIED_SINCE'], 2);
		$_SERVER['HTTP_IF_MODIFIED_SINCE'] = $a = strtotime($a[0]);
		$_SERVER['HTTP_IF_NONE_MATCH'] = '"' . dechex($a) . '"';
		$patchwork_private = true;
	}
	else if (27 == strlen($a) && '"-------------------------"' == strtr($a, '0123456789abcdef', '----------------'))
	{
		$b = PATCHWORK_ZCACHE . $a[1] .'/'. $a[2] .'/'. substr($a, 3, 6) .'.validator.'. DEBUG .'.txt';
		if (file_exists($b) && substr(file_get_contents($b), 0, 8) == substr($a, 9, 8))
		{
			$private = substr($a, 17, 1);
			$maxage  = hexdec(substr($a, 18, 8));

			header('HTTP/1.1 304 Not Modified');
			header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', $_SERVER['REQUEST_TIME'] + ($private || !$maxage ? 0 : $maxage)));
			header('Cache-Control: max-age=' . $maxage . ($private ? ',private,must' : ',public,proxy') . '-revalidate');
			exit;
		}
	}
}
// }}}


// Shortcut for patchwork::*
class p extends patchwork {}

/* Let's go */
patchwork::start();
