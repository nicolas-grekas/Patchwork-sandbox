<?php /*********************************************************************
 *
 *   Copyright : (C) 2006 Nicolas Grekas. All rights reserved.
 *   Email     : nicolas.grekas+patchwork@espci.org
 *   License   : http://www.gnu.org/licenses/gpl.txt GNU/GPL, see COPYING
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU General Public License as published by
 *   the Free Software Foundation; either version 2 of the License, or
 *   (at your option) any later version.
 *
 ***************************************************************************/

/*>
if (isset($_SERVER['PHP_AUTH_USER']))
{
	$_SERVER['PHP_AUTH_USER'] = $_SERVER['PHP_AUTH_PW'] = "Don't use me, it would be a security hole (Cross Site Javascript Request).";
}
<*/

// {{{ Shortcut functions for applications developpers
function G($name, $type) {$a = func_get_args(); return VALIDATE::get(    $_GET[$name]   , $type, array_slice($a, 2));}
function P($name, $type) {$a = func_get_args(); return VALIDATE::get(    $_POST[$name]  , $type, array_slice($a, 2));}
function C($name, $type) {$a = func_get_args(); return VALIDATE::get(    $_COOKIE[$name], $type, array_slice($a, 2));}
function F($name, $type) {$a = func_get_args(); return VALIDATE::getFile($_FILES[$name] , $type, array_slice($a, 2));}

function V($var , $type) {$a = func_get_args(); return VALIDATE::get(     $var          , $type, array_slice($a, 2));}

function T($string, $lang = false)
{
	if (!$lang) $lang = CIA::__LANG__();
	return TRANSLATE::get($string, $lang, true);
}
// }}}

// {{{ hunter: a user function is triggered when a hunter object is destroyed
class hunter
{
	protected $function;
	protected $param_arr;

	function __construct($function, $param_arr)
	{
		$this->function =& $function;
		$this->param_arr =& $param_arr;
	}

	function __destruct()
	{
		call_user_func_array($this->function, $this->param_arr);
	}
}
// }}}

// {{{ PHP session mechanism overloading
class sessionHandler implements ArrayAccess
{
	function offsetGet($k)     {$_SESSION = SESSION::getAll(); return $_SESSION[$k];}
	function offsetSet($k, $v) {$_SESSION = SESSION::getAll(); $_SESSION[$k] =& $v;}
	function offsetExists($k)  {$_SESSION = SESSION::getAll(); return isset($_SESSION[$k]);}
	function offsetUnset($k)   {$_SESSION = SESSION::getAll(); unset($_SESSION[$k]);}

	static $id;

	static function close()   {return true;}
	static function gc($life) {return true;}

	static function open($path, $name)
	{
		session_cache_limiter('');
		ini_set('session.use_only_cookies', true);
		ini_set('session.use_cookies', false);
		ini_set('session.use_trans_sid', false);
		return true;
	}

	static function read($id)
	{
		$_SESSION = SESSION::getAll();
		self::$id = $id;
		return '';
	}

	static function write($id, $data)
	{
		if (self::$id != $id) SESSION::regenerateId();
		return true;
	}

	static function destroy($id)
	{
		SESSION::regenerateId(true);
		return true;
	}
}

session_set_save_handler(
	array($k = 'sessionHandler', 'open'),
	array($k, 'close'),
	array($k, 'read'),
	array($k, 'write'),
	array($k, 'destroy'),
	array($k, 'gc')
);

$_SESSION = new sessionHandler;
// }}}

// {{{ Database sugar
function DB($close = false)
{
	static $hunter;
	static $db = false;

	if ($db || $close)
	{
		if ($close && $db) $db = adapter_DB::close($db) && false;
	}
	else
	{
		$hunter = new hunter('DB', array(true));
		$db = adapter_DB::connect();
	}

	return $db;
}
// }}}

function jsquote($a, $addDelim = true, $delim = "'")
{
	if ((string) $a === (string) ($a-0)) return $a-0;

	false !== strpos($a, "\r") && $a = strtr(str_replace("\r\n", "\n", $a), "\r", "\n");
	false !== strpos($a, '\\') && $a = str_replace('\\', '\\\\', $a);
	false !== strpos($a, "\n") && $a = str_replace("\n", '\n', $a);
	false !== strpos($a, '</') && $a = str_replace('</', '<\\/', $a);
	false !== strpos($a, $delim) && $a = str_replace($delim, '\\' . $delim, $a);

	if ($addDelim) $a = $delim . $a . $delim;

	return $a;
}

function jsquoteRef(&$a) {$a = jsquote($a);}

class
{
	static $cachePath;
	static $agentClass;
	static $catchMeta = false;
	static $ETag = '';

	protected static $host;
	protected static $lang = '__';
	protected static $home;
	protected static $uri;

	protected static $versionId;
	protected static $fullVersionId;
	protected static $handlesOb = false;
	protected static $metaInfo;
	protected static $metaPool = array();
	protected static $isGroupStage = true;
	protected static $isServersideHtml = false;
	protected static $binaryMode = false;

	protected static $maxage = false;
	protected static $private = false;
	protected static $expires = 'auto';
	protected static $watchTable = array();
	protected static $headers = array();

	protected static $redirecting = false;
	protected static $is_enabled = false;
	protected static $ob_starting_level;
	protected static $ob_level;
	protected static $varyEncoding = false;
	protected static $contentEncoding = false;
	protected static $is304 = false;

	protected static $agentClasses = '';
	protected static $privateDetectionMode = false;
	protected static $detectCSRF = false;
	protected static $total_time = 0;

	protected static $allowGzip = array(
		'text/','script','xml','html','bmp','wav',
		'msword','rtf','excel','powerpoint',
	);

	protected static $ieSniffedTypes = array(
		'text/plain','text/richtext','audio/x-aiff','audio/basic','audio/wav',
		'image/gif','image/jpeg','image/pjpeg','image/tiff','image/x-png','image/png',
		'image/x-xbitmap','image/bmp','image/x-jg','image/x-emf','image/x-wmf',
		'video/avi','video/mpeg','application/octet-stream','application/pdf',
		'application/base64','application/macbinhex40','application/postscript',
		'application/x-compressed','application/java','application/x-msdownload',
		'application/x-gzip-compressed','application/x-zip-compressed'
	);

	protected static $ieSniffedTags = array(
		'body','head','html','img','plaintext',
		'pre','script','table','title'
	);

	static function start()
	{
		ini_set('log_errors', true);
		ini_set('error_log', './error.log');
		ini_set('display_errors', false);

		class_exists('CIA_preprocessor__0', false) && CIA_preprocessor__0::$function += array(
			'header'       => 'CIA::header',
			'setcookie'    => 'CIA::setcookie',
			'setrawcookie' => 'CIA::setrawcookie',
		);

		self::$fullVersionId = $GLOBALS['version_id'];
		self::$versionId = abs(self::$fullVersionId % 10000);

		self::$cachePath = resolvePath('zcache/');
		if (!self::$cachePath) self::$cachePath = $GLOBALS['cia_paths'][count($GLOBALS['cia_paths']) - 2] . '/zcache/';

		self::header('Content-Type: text/html; charset=UTF-8');
		set_error_handler(array(__CLASS__, 'error_handler'));

		self::$is_enabled = true;
		self::$ob_starting_level = ob_get_level();
		ob_start(array(__CLASS__, 'ob_sendHeaders'));
		ob_start(array(__CLASS__, 'ob_filterOutput'), 8192);
		self::$ob_level = 2;


		self::setLang($_SERVER['CIA_LANG'] ? $_SERVER['CIA_LANG'] : substr($GLOBALS['CONFIG']['lang_list'], 0, 2));

		if (htmlspecialchars(self::$home) != self::$home)
		{
			W('Fatal error: illegal character found in self::$home');
			self::disable(true);
		}

		if (isset($_GET['T$'])) self::$private = true;

		$agent = $_SERVER['CIA_REQUEST'];
		if (($mime = strrchr($agent, '.')) && strcasecmp('.tpl', $mime)) CIA_controler::call($agent, $mime);

/*>
		self::log(
			'<a href="' . htmlspecialchars($_SERVER['REQUEST_URI']) . '" target="_blank">'
			. htmlspecialchars(preg_replace("'&v\\\$=[^&]*'", '', $_SERVER['REQUEST_URI']))
			. '</a>'
		);
<*/

		CIA_DIRECT ? self::clientside() : self::serverside();

		while (self::$ob_level)
		{
			ob_end_flush();
			--self::$ob_level;
		}

#>		self::log('', true);
	}

	// {{{ Client side rendering controler
	static function clientside()
	{
		self::header('Content-Type: text/javascript; charset=UTF-8');

		if (isset($_GET['v$']) && self::$versionId != $_GET['v$'] && 'x$' != key($_GET))
		{
			echo 'w(w.r(1,' . (int)!DEBUG . '))';
			return;
		}

		switch ( key($_GET) )
		{
		case 't$':
			CIA_sendTemplate::call();
			break;

		case 'p$':
			CIA_sendPipe::call();
			break;

		case 'a$':
			CIA_clientside::render(array_shift($_GET), false);
			break;

		case 'x$':
			CIA_clientside::render(array_shift($_GET), true);
			break;
		}
	}
	// }}}

	// {{{ Server side rendering controler
	static function serverside()
	{
		$agent = self::resolveAgentClass($_SERVER['CIA_REQUEST'], $_GET);

		if (isset($_GET['k$'])) return CIA_sendTrace::call($agent);

		self::$binaryMode = (bool) constant("$agent::binary");

		// Synch exoagents on browser request
		if (isset($_COOKIE['cache_reset_id']) && self::$versionId == $_COOKIE['cache_reset_id'] && setcookie('cache_reset_id', '', 0, '/'))
		{
			touch('./config.php');
			self::touch('foreignTrace');
			self::touch('CIApID');

			self::setMaxage(0);
			self::setGroup('private');

			echo '<html><head><script type="text/javascript">location.reload()</script></head></html>';
			return;
		}

/*>
		if (CIA_CHECK_SOURCE && !self::$binaryMode)
		{
			self::$fullVersionId = -self::$fullVersionId - filemtime('./config.php');
			touch('./config.php', $_SERVER['REQUEST_TIME']);
			self::$fullVersionId = -self::$fullVersionId - $_SERVER['REQUEST_TIME'];
			self::$versionId = abs(self::$fullVersionId % 10000);

			self::touch('');
			foreach (glob(self::$cachePath . '?/?/*', GLOB_NOSORT) as $v) if ('.session' != substr($v, -8)) unlink($v);

			if (!CIA_POSTING)
			{
				self::setMaxage(0);
				self::setGroup('private');

				echo '<html><head><script type="text/javascript">location.reload()</script></head></html>';
				return;
			}
		}
<*/

		// load agent
		if (CIA_POSTING || self::$binaryMode || isset($_GET['$bin']) || !isset($_COOKIE['JS']) || !$_COOKIE['JS'])
		{
			if (!self::$binaryMode) self::setGroup('private');
			CIA_serverside::loadAgent($agent, false, false);
		}
		else CIA_clientside::loadAgent($agent);
	}
	// }}}

	static function disable($exit = false)
	{
		if (self::$is_enabled && ob_get_level() == self::$ob_starting_level + self::$ob_level)
		{
			while (self::$ob_level-- > 2) ob_end_clean();

			ob_end_clean();
			self::$is_enabled = false;
			ob_end_clean();
			self::$ob_level = 0;

			if (self::$is304) exit;
			if (!$exit) return true;
		}

		if ($exit) exit;

		return false;
	}

	static function setLang($new_lang)
	{
		$lang = self::$lang;
		self::$lang = $new_lang;

		self::$home = explode('__', $_SERVER['CIA_HOME'], 2);
		self::$home = implode($new_lang, self::$home);

		self::$host = strtr(self::$home, '#?', '//');
		self::$host = substr(self::$home, 0, strpos(self::$host, '/', 8)+1);

		return $lang;
	}

	static function __HOST__() {return self::$host;}
	static function __LANG__() {return self::$lang;}
	static function __HOME__() {return self::$home;}
	static function __URI__() {return self::$uri;}

	static function home($url, $noId = false)
	{
		if (!preg_match("'^https?://'", $url))
		{
			if (strncmp('/', $url, 1)) $url = self::$home . $url;
			else $url = self::$host . substr($url, 1);

			if (!$noId) $url .= (false === strpos($url, '?') ? '?' : '&amp;') . self::$versionId;
		}

		return $url;
	}

	/*
	 * Replacement for PHP's header() function
	 */
	static function header($string, $replace = true, $http_response_code = null)
	{
		if (self::$is_enabled && (
			   0===stripos($string, 'http/')
			|| 0===stripos($string, 'etag')
			|| 0===stripos($string, 'last-modified')
			|| 0===stripos($string, 'expires')
			|| 0===stripos($string, 'cache-control')
			|| 0===stripos($string, 'content-length')
		)) return;

		$string = preg_replace("'[\r\n].*'s", '', $string);

		$name = strtolower(substr($string, 0, strpos($string, ':')));

		if (self::$catchMeta) self::$metaInfo[4][$name] = $string;

		if ('content-type' == $name) self::$isServersideHtml = false !== stripos($string, 'html');

		if (!self::$privateDetectionMode)
		{
			if ('content-type' == $name && false !== stripos($string, 'script'))
			{
				if (self::$private) CIA_TOKEN_MATCH || CIA_alertCSRF::call();

				self::$detectCSRF = true;
			}

			self::$headers[$name] = $replace || !isset(self::$headers[$name]) ? $string : (self::$headers[$name] . ', ' . $string);
			header($string, $replace, self::$is_enabled ? null : $http_response_code);
		}
	}

	static function setcookie($name, $value = '', $expires = null, $path = '', $domain = '', $secure = false, $httponly = false)
	{
		self::setrawcookie($name, urlencode($value), $expires, $path, $domain, $secure, $httponly);
	}
	
	static function setrawcookie($name, $value = '', $expires = 0, $path = '', $domain = '', $secure = false, $httponly = false)
	{
		isset($_SERVER['HTTP_USER_AGENT']) && strpos($_SERVER['HTTP_USER_AGENT'], 'MSIE 5') && $httponly = false;

		if ($value != strtr($value, ",; \t\r\n\013\014", '--------')) setrawcookie($name, $value, $expires, $path, $domain, $secure);
		else
		{
			('' === (string) $value) && $expires = 1;
	
			header(
				"Set-Cookie: {$name}={$value}" .
					($expires  ? '; expires=' . date('D, d-M-Y H:i:s T', $expires) : '') .
					($path     ? '; path='   . $path   : '') .
					($domain   ? '; domain=' . $domain : '') .
					($secure   ? '; secure'   : '') .
					($httponly ? '; HttpOnly' : ''),
				false
			);
		}
	}

	static function gzipAllowed()
	{
		$c = substr(self::$headers['content-type'], 14);

		foreach (self::$allowGzip as $p) if (false !== stripos($c, $p)) return true;

		return false;
	}

	static function readfile($file, $mime = 'application/octet-stream')
	{
		$size = filesize($file);

		self::header('Content-Type: ' . $mime);
		self::$isServersideHtml = false;
		self::$ETag = $size .'-'. filemtime($file) .'-'. fileinode($file);
		self::disable();

		$gzip = self::gzipAllowed();
		$gzip || ob_start();

		ob_start(array(__CLASS__, 'ob_filterOutput'), 8192);

		$h = fopen($file, 'rb');
		echo fread($h, 256); // For CIA::ob_filterOutput to fix IE

		if ($gzip)
		{
			if ('HEAD' == $_SERVER['REQUEST_METHOD']) ob_end_clean();
			$data = '';
		}
		else
		{
			ob_end_flush();
			$data = ob_get_clean();
			$size += strlen($data) - 256;
			header('Content-Length: ' . $size);
		}

		if ('HEAD' != $_SERVER['REQUEST_METHOD'])
		{
			echo $data;
			feof($h) || fpassthru($h);
		}

		fclose($h);
	}

	/*
	 * Redirect the web browser to an other GET request
	 */
	static function redirect($url = '')
	{
		if (self::$privateDetectionMode) throw new Exception;

		$url = (string) $url;
		$url = '' === $url ? '' : (preg_match("'^([^:/]+:/|\.+)?/'i", $url) ? $url : (self::$home . ('index' == $url ? '' : $url)));

		self::$redirecting = true;
		self::disable();

		if (CIA_DIRECT)
		{
			$url = 'location.replace(' . ('' !== $url
				? "'" . addslashes($url) . "'"
				: 'location')
			. ')';

			if (true === self::$contentEncoding) header('Content-Encoding: identity');
			header('Content-Length: ' . strlen($url));

			echo $url;
		}
		else
		{
			header('HTTP/1.1 302 Found');
			header('Location: ' . ('' !== $url ? $url : $_SERVER['REQUEST_URI']));
		}

		exit;
	}

	protected static function openMeta($agentClass, $is_trace = true)
	{
		self::$isGroupStage = true;

		self::$agentClass = $agentClass;
		if ($is_trace) self::$agentClasses .= '*' . self::$agentClass;

		$default = array(false, array(), false, array(), array(), false, self::$agentClass);

		self::$catchMeta = true;

		self::$metaPool[] =& $default;
		self::$metaInfo =& $default;
	}

	protected static function closeGroupStage()
	{
		self::$isGroupStage = false;

		return self::$metaInfo[1];
	}

	protected static function closeMeta()
	{
		self::$catchMeta = false;

		$poped = array_pop(self::$metaPool);

		$len = count(self::$metaPool);

		if ($len)
		{
			self::$metaInfo =& self::$metaPool[$len-1];
			self::$agentClass = self::$metaInfo[6];
		}
		else self::$agentClass = self::$metaInfo = null;

		return $poped;
	}

	/*
	 * Controls the Cache's max age.
	 */
	static function setMaxage($maxage)
	{
		if ($maxage < 0) $maxage = CIA_MAXAGE;
		else $maxage = min(CIA_MAXAGE, $maxage);

		if (!self::$privateDetectionMode)
		{
			if (false === self::$maxage) self::$maxage = $maxage;
			else self::$maxage = min(self::$maxage, $maxage);
		}

		if (self::$catchMeta)
		{
			if (false === self::$metaInfo[0]) self::$metaInfo[0] = $maxage;
			else self::$metaInfo[0] = min(self::$metaInfo[0], $maxage);
		}
	}

	/*
	 * Controls the Cache's groups.
	 */
	static function setGroup($group)
	{
		if ('public' == $group) return;

		$group = array_diff((array) $group, array('public'));

		if (!$group) return;

		if (self::$privateDetectionMode) throw new PrivateDetection;
		else if (self::$detectCSRF) CIA_TOKEN_MATCH || CIA_alertCSRF::call();

		self::$private = true;

		if (self::$catchMeta)
		{
			$a =& self::$metaInfo[1];

			if (1 == count($a) && 'private' == $a[0]) return;

			if (in_array('private', $group)) $a = array('private');
			else
			{
				$b = $a;

				$a = array_unique( array_merge($a, $group) );
				sort($a);

				if ($b != $a && !self::$isGroupStage)
				{
#>					W('Misconception: CIA::setGroup() is called in ' . self::$agentClass . '->compose( ) rather than in ' . self::$agentClass . '->control(). Cache is now disabled for this agent.');

					$a = array('private');
				}
			}
		}
	}

	/*
	 * Controls the Cache's expiration mechanism.
	 */
	static function setExpires($expires)
	{
		if (!self::$privateDetectionMode) if ('auto' == self::$expires || 'ontouch' == self::$expires) self::$expires = $expires;

		if (self::$catchMeta) self::$metaInfo[2] = $expires;
	}

	static function watch($watch)
	{
		if (self::$catchMeta) self::$metaInfo[3] = array_merge(self::$metaInfo[3], (array) $watch);
	}

	static function canPost()
	{
		if (self::$catchMeta) self::$metaInfo[5] = true;
	}

	static function string($a)
	{
		return is_object($a) ? $a->__toString() : (string) $a;
	}

	static function uniqid() {return md5(uniqid(mt_rand(), true));}

	/*
	 * Revokes every agent watching $message
	 */
	static function touch($message)
	{
		if (is_array($message)) foreach ($message as &$message) self::touch($message);
		else
		{
			$message = preg_split("'[\\\\/]+'u", $message, -1, PREG_SPLIT_NO_EMPTY);
			$message = array_map('rawurlencode', $message);
			$message = implode('/', $message);
			$message = str_replace('.', '%2E', $message);

			$i = 0;

			@include self::getCachePath('watch/' . $message, 'php');

#>			E("CIA::touch('$message'): $i file(s) deleted.");
		}
	}

	/*
	 * Like mkdir(), but works with multiple level of inexistant directory
	 */
	static function makeDir($dir)
	{
		$dir = dirname($dir . ' ');

		if (is_dir($dir)) return;

		$dir = preg_split("'[/\\\\]+'u", $dir);

		if (!$dir) return;

		if ($dir[0]==='')
		{
			array_shift($dir);
			if (!$dir) return;
			$dir[0] = '/' . $dir[0];
		}
		else if (!(CIA_WINDOWS && ':' == substr($dir[0], -1))) $dir[0] = './' . $dir[0];

		$new = array();

		while ($dir && !is_dir( implode('/', $dir)))
		{
			$new[] = array_pop($dir);
		}

		if ($new)
		{
			$dir = implode('/', $dir);

			while ($new)
			{
				$dir .= '/' . array_pop($new);
				mkdir($dir);
			}
		}
	}

	static function fopenX($file, &$readHandle = false)
	{
		self::makeDir($file);

		if ($h = @fopen($file, 'xb')) flock($h, LOCK_EX);
		else if ($readHandle)
		{
			$readHandle = fopen($file, 'rb');
			flock($readHandle, LOCK_SH);
		}

		return $h;
	}

	/*
	 * Creates the full directory path to $filename, then writes $data into this file
	 */
	static function writeFile($filename, &$data, $Dmtime = 0)
	{
		$tmpname = dirname($filename) . '/' . self::uniqid();

		$h = @fopen($tmpname, 'wb');

		if (!$h)
		{
			self::makeDir($tmpname);
			$h = @fopen($tmpname, 'wb');
		}

		if ($h)
		{
			fwrite($h, $data, strlen($data));
			fclose($h);

			if (CIA_WINDOWS)
			{
				file_exists($filename) && unlink($filename);
				rename($tmpname, $filename);
			}
			else rename($tmpname, $filename);

			if ($Dmtime) touch($filename, $_SERVER['REQUEST_TIME'] + $Dmtime);

			return true;
		}
		else return false;
	}


	protected static function getCachePath($filename, $extension, $key = '')
	{
		if (''!==(string)$extension) $extension = '.' . $extension;

		$hash = md5($filename . $extension . '.'. $key);
		$hash = $hash{0} . '/' . $hash{1} . '/' . substr($hash, 2);

		$filename = rawurlencode(str_replace('/', '.', $filename));
		$filename = substr($filename, 0, 224 - strlen($extension));

		return self::$cachePath . $hash . '.' . $filename . $extension;
	}

	static function getContextualCachePath($filename, $extension, $key = '')
	{
		return self::getCachePath($filename, $extension, self::$home .'-'. self::$lang .'-'. DEBUG .'-'. CIA_PROJECT_PATH .'-'. $key);
	}

	static function log($message, $is_end = false, $raw_html = true)
	{
		static $prev_time = CIA;
		self::$total_time += $a = 1000*(microtime(true) - $prev_time);

		if ('__getDeltaMicrotime' !== $message)
		{
			if ($is_end) $a = sprintf('Total: %.02f ms</pre><pre>', self::$total_time);
			else
			{
				$b = self::$handlesOb ? serialize($message) : print_r($message, true);

				if (!$raw_html) $b = htmlspecialchars($b);

				$a = '<acronym title="' . date("d-m-Y H:i:s", $_SERVER['REQUEST_TIME']) . '">' . sprintf('%.02f', $a) . ' ms</acronym>: ' . $b . "\n";
			}

			$b = ini_get('error_log');
			$b = fopen($b ? $b : './error.log', 'ab');
			flock($b, LOCK_EX);
			fwrite($b, $a, strlen($a));
			fclose($b);
		}

		$prev_time = microtime(true);

		return $a;
	}

	protected static function resolveAgentClass($agent, &$args)
	{
		static $resolvedCache = array();

		if (isset($resolvedCache[$agent])) return 'agent_' . strtr($agent, '/', '_');

		if (preg_match("''u", $agent))
		{
			$agent = '/' . $agent . '/';
			while (false !== strpos($agent, '//')) $agent = str_replace('//', '/', $agent);

			preg_match('"^/(?:[a-zA-Z0-9\x80-\xff]+(?:([-_ ])[a-zA-Z0-9\x80-\xff]+)*/)*"', $agent, $a);
		}
		else $a = array('/');

		$param = (string) substr($agent, strlen($a[0]), -1);
		$param = '' !== $param ? explode('/', $param) : array();
		$agent = (string) substr($a[0], 1, -1);

		if (isset($a[1])) $agent = (string) preg_replace("'[-_ ](.)'eu", "mb_strtoupper('$1')", $agent);
		if ('' === $agent) $agent = 'index';

		$potentialAgent = $agent;

		$lang = self::$lang;
		$createTemplate = true;

		while (1)
		{
			if (isset($resolvedCache[$potentialAgent]))
			{
				$createTemplate = false;
				break;
			}

			if (resolvePath("class/agent/{$potentialAgent}.php"))
			{
				$createTemplate = false;
				break;
			}

			if (resolvePath("public/{$lang}/{$potentialAgent}.tpl")) break;
			if (resolvePath("public/__/{$potentialAgent}.tpl")) break;

			if ('index' == $potentialAgent) break;


			$a = strrpos($agent, '/');

			if ($a)
			{
				array_unshift($param, substr($agent, $a + 1));
				$agent = substr($agent, 0, $a);
				$potentialAgent = substr($potentialAgent, 0, strrpos($potentialAgent, '/'));
			}
			else
			{
				array_unshift($param, $agent);
				$potentialAgent = $agent = 'index';
			}
		}

		if ($param)
		{
			$args['__0__'] = implode('/', $param);

			$i = 0;
			foreach ($param as &$param) $args['__' . ++$i . '__'] = $param;
		}

		$resolvedCache[$potentialAgent] = true;

		$agent = 'agent_' . str_replace('/', '_', $potentialAgent);

		if ($createTemplate) eval('class ' . $agent . ' extends agent{protected $maxage=-1;protected $watch=array(\'public/templates\');function control(){}}');

		return $agent;
	}

	protected static function agentArgv($agent)
	{
		// get declared arguments in $agent::$argv public property
		$args = get_class_vars($agent);
		$args =& $args['argv'];

		if (is_array($args)) array_walk($args, array('self', 'stripArgv'));
		else $args = array();

		// autodetect private data for antiCSRF
		$cache = self::getContextualCachePath('antiCSRF.' . $agent, 'txt');
		$readHandle = true;
		if ($h = self::fopenX($cache, $readHandle))
		{
			$private = '';

			self::$privateDetectionMode = true;

			try
			{
				new $agent;
			}
			catch (PrivateDetection $d)
			{
				$private = '1';
			}
			catch (Exception $d)
			{
			}

			fwrite($h, $private, strlen($private));
			fclose($h);

			self::$privateDetectionMode = false;

			if ($private) $args[] = 'T$';
		}
		else
		{
			$cache = fstat($readHandle);
			fclose($readHandle);
			if ($cache['size']) $args[] = 'T$';
		}

		return $args;
	}

	protected static function stripArgv(&$a, $k)
	{
		if (is_string($k)) $a = $k;

		$b = strpos($a, ':');
		if (false !== $b) $a = substr($a, 0, $b);
	}

	protected static function agentCache($agentClass, $keys, $type, $group = false)
	{
		if (false === $group) $group = self::$metaInfo[1];
		$keys = serialize(array($keys, $group));

		return self::getContextualCachePath($agentClass, $type . '.php', $keys);
	}

	static function writeWatchTable($message, $file, $exclusive = true)
	{
		$file = realpath($file);
		if (!$file) return;

		$file =  "++\$i;unlink('" . str_replace(array('\\',"'"), array('\\\\',"\\'"), $file) . "');\n";

		foreach (array_unique((array) $message) as $message)
		{
			if (self::$catchMeta) self::$metaInfo[3][] = $message;

			$message = preg_split("'[\\\\/]+'u", $message, -1, PREG_SPLIT_NO_EMPTY);
			$message = array_map('rawurlencode', $message);
			$message = implode('/', $message);
			$message = str_replace('.', '%2E', $message);

			$path = self::getCachePath('watch/' . $message, 'php');
			if ($exclusive) self::$watchTable[] = $path;

			self::makeDir($path);

			$h = fopen($path, 'a+b');
			flock($h, LOCK_EX);
			fseek($h, 0, SEEK_END);
			if ($file_isnew = !ftell($h)) $file = "<?php ++\$i;unlink(__FILE__);\n" . $file;
			fwrite($h, $file, strlen($file));
			fclose($h);

			if ($file_isnew)
			{
				$message = explode('/', $message);
				while (array_pop($message) !== null)
				{
					$file = "include '" . str_replace(array('\\', "'"), array('\\\\', "\\'"), $path) . "';\n";

					$path = self::getCachePath('watch/' . implode('/', $message), 'php');

					self::makeDir($path);

					$h = fopen($path, 'a+b');
					flock($h, LOCK_EX);
					fseek($h, 0, SEEK_END);
					if ($file_isnew = !ftell($h)) $file = "<?php ++\$i;unlink(__FILE__);\n" . $file;
					fwrite($h, $file, strlen($file));
					fclose($h);

					if (!$file_isnew) break;
				}
			}
		}
	}


	static function &ob_filterOutput(&$buffer, $mode)
	{
		self::$handlesOb = true;

		$one_chunk = $mode == (PHP_OUTPUT_HANDLER_START | PHP_OUTPUT_HANDLER_END);

		if ('' === $buffer && $one_chunk)
		{
			self::$handlesOb = false;
			return $buffer;
		}


		$type = isset(self::$headers['content-type']) ? strtolower(substr(self::$headers['content-type'], 14)) : false;

		// Anti-XSRF token

		if (false !== strpos($type, 'html'))
		{
			static $lead;

			if (PHP_OUTPUT_HANDLER_START & $mode)
			{
				$lead = '';
#>				if (self::$isServersideHtml && (!CIA_CHECK_SOURCE || CIA_POSTING) && !self::$binaryMode) $buffer = CIA_debugWin::prolog() . $buffer;
			}

			$tail = '';

			if (!(PHP_OUTPUT_HANDLER_END & $mode))
			{
				$meta = strrpos($buffer, '<');
				if (false !== $meta)
				{
					$tail = strrpos($buffer, '>');
					if (false !== $tail && $tail > $meta) $meta = $tail;

					$tail = substr($buffer, $meta);
					$buffer = substr($buffer, 0, $meta);
				}
			}

			$buffer = $lead . $buffer;
			$lead = $tail;


			$meta = stripos($buffer, '<form');
			if (false !== $meta)
			{
				$meta = preg_replace_callback(
					'#<form\s(?:[^>]+?\s)?method\s*=\s*(["\']?)post\1.*?>#iu',
					array('CIA_appendAntiCSRF', 'call'),
					$buffer
				);

				if ($meta != $buffer)
				{
					self::$private = true;
					if (!(isset($_COOKIE['JS']) && $_COOKIE['JS'])) self::$maxage = 0;
					$buffer = $meta;
				}

				unset($meta);
			}
		}
		else if (PHP_OUTPUT_HANDLER_START & $mode)
		{
			// Fix IE mime-sniff misfeature
			// (see http://www.splitbrain.org/blog/2007-02/12-internet_explorer_facilitates_cross_site_scripting
			//  and http://msdn.microsoft.com/library/default.asp?url=/workshop/networking/moniker/overview/appendix_a.asp)
			// This will likely break binary contents, but it is also very unlikely
			// that legitimate binary contents may contain these suspicious bytes.

			$meta = substr($buffer, 0, 256);
			$lt = strpos($meta, '<');
			if (false !== $lt && (!$type || in_array($type, self::$ieSniffedTypes)))
			{
				foreach (self::$ieSniffedTags as $tag)
				{
					$tail = stripos($meta, '<' . $tag, $lt);
					if (false !== $tail && $tail + strlen($tag) < strlen($meta))
					{
						$buffer = substr($buffer, 0, $tail)
							. '<!--IE-MimeSniffFix'
							. str_repeat('-', 233 - strlen($tag) - $tail)
							. '-->'
							. substr($buffer, $tail);

						break;
					}
				}
			}
		}


		// GZip compression

		if (self::gzipAllowed())
		{
			if ($one_chunk)
			{
				if (strlen($buffer) > 100)
				{
					self::$varyEncoding = true;
					self::$is_enabled || header('Vary: Accept-Encoding');

					$mode = isset($_SERVER['HTTP_ACCEPT_ENCODING']) ? $_SERVER['HTTP_ACCEPT_ENCODING'] : '';

					if ($mode)
					{
						$algo = array(
							'deflate'  => 'gzdeflate',
							'gzip'     => 'gzencode',
							'compress' => 'gzcompress',
						);

						foreach ($algo as $encoding => $algo) if (false !== stripos($mode, $encoding))
						{
							self::$contentEncoding = $encoding;
							self::$is_enabled || header('Content-Encoding: ' . $encoding);
							$buffer = $algo($buffer);
							break;
						}
					}
				}
			}
			else
			{
				self::$contentEncoding = true;
				self::$varyEncoding = true;
				if (!self::$is_enabled && (PHP_OUTPUT_HANDLER_START & $mode)) header('Vary: Accept-Encoding');
				$buffer = ob_gzhandler($buffer, $mode);
			}
		}

		if ($one_chunk && !self::$is_enabled) header('Content-Length: ' . strlen($buffer));

		self::$handlesOb = false;
		return $buffer;
	}

	static function &ob_sendHeaders(&$buffer)
	{
		self::$handlesOb = true;


		if (self::$redirecting)
		{
			$buffer = '';
			self::$handlesOb = false;
			return $buffer;
		}


		$is304 = false;

		if (!CIA_POSTING && ('' !== $buffer || self::$ETag))
		{
			if (!self::$maxage) self::$maxage = 0;


			/* ETag / Last-Modified validation */

			$meta = self::$maxage . "\n"
				. self::$private . "\n"
				. implode("\n", self::$headers);

			$ETag = substr(md5(self::$ETag .'-'. $buffer .'-'. self::$expires .'-'. $meta), 0, 8);
			$ETag = hexdec($ETag);
			if ($ETag > 2147483647) $ETag -= 2147483648;

			$LastModified = gmdate('D, d M Y H:i:s \G\M\T', $ETag);
			$ETag = dechex($ETag);


			$is304 = (isset($_SERVER['HTTP_IF_NONE_MATCH']) && false!==strpos($_SERVER['HTTP_IF_NONE_MATCH'], $ETag))
				|| (isset($_SERVER['HTTP_IF_MODIFIED_SINCE']) && false!==strpos($_SERVER['HTTP_IF_MODIFIED_SINCE'], $LastModified));


			if ('ontouch' == self::$expires || ('auto' == self::$expires && self::$watchTable))
			{
				self::$expires = 'auto';
				$ETag = '-' . $ETag;
			}


			/* Write watch table */

			if ('auto' == self::$expires && self::$watchTable)
			{
				$validator = self::$cachePath . $ETag[1] .'/'. $ETag[2] .'/'. substr($ETag, 3) .'.validator.'. DEBUG .'.';
				$validator .= md5($_SERVER['CIA_HOME'] .'-'. $_SERVER['CIA_LANG'] .'-'. CIA_PROJECT_PATH .'-'. $_SERVER['REQUEST_URI']) . '.txt';

				if ($h = self::fopenX($validator))
				{
					fwrite($h, $meta, strlen($meta));
					fclose($h);

					$a = "++\$i;unlink('$validator');\n";

					foreach (array_unique(self::$watchTable) as $path)
					{
						$h = fopen($path, 'ab');
						flock($h, LOCK_EX);
						fwrite($h, $a, strlen($a));
						fclose($h);
					}

					self::writeWatchTable('CIApID', $validator);
				}
			}

			header('ETag: "' . $ETag . '"');
			header('Last-Modified: ' . $LastModified);
			header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', time() + (self::$private || !self::$maxage ? 0 : self::$maxage)));
			header('Cache-Control: max-age=' . self::$maxage . (self::$private ? ',private,must' : ',public,proxy') . '-revalidate');
			self::$varyEncoding && header('Vary: Accept-Encoding');

			if ($is304)
			{
				$buffer = '';
				header('HTTP/1.1 304 Not Modified');
			}
		}

		if (!$is304)
		{
			is_string(self::$contentEncoding) && header('Content-Encoding: ' . self::$contentEncoding);
			self::$is_enabled                 && header('Content-Length: ' . strlen($buffer));
		}

		self::$is304 = $is304;

		self::$handlesOb = false;
		if ('HEAD' == $_SERVER['REQUEST_METHOD']) $buffer = '';

		return $buffer;
	}

	static function error_handler($code, $message, $file, $line, $context)
	{
		if (!error_reporting()) return;

		static $offset = 0;
		$offset || $offset = -13 - strlen($GLOBALS['cia_paths_token']);

		if (
			   ((E_NOTICE == $code || E_STRICT == $code) && '-' == substr($file, $offset, 1))
			|| (E_WARNING == $code && false !== stripos($message, 'safe mode'))
		) return;

		CIA_error::call($code, $message, $file, $line, &$context);
	}
}

class agent
{
	const binary = false;

	public $argv = array();

	protected $template = '';

	protected $maxage  = 0;
	protected $expires = 'auto';
	protected $canPost = false;
	protected $watch = array();

	function control() {}
	function compose($o) {return $o;}
	function getTemplate()
	{
		return $this->template ? $this->template : str_replace('_', '/', substr(get_class($this), 6));
	}

	final public function __construct($args = array())
	{
		$a = (array) $this->argv;

		$this->argv = (object) array();
		$_GET = array();

		foreach ($a as $key => &$a)
		{
			if (is_string($key))
			{
				$default = $a;
				$a = $key;
			}
			else $default = '';

			$a = explode(':', $a);
			$key = array_shift($a);

			$b = isset($args[$key]) ? (string) $args[$key] : $default;
			if (false !== strpos($b, "\0")) $b = str_replace("\0", '', $b);

			if ($a)
			{
				$b = VALIDATE::get($b, array_shift($a), $a);
				if (false === $b) $b = $default;
			}

			$_GET[$key] = $this->argv->$key = $b;
		}

		$this->control();
	}

	function metaCompose()
	{
		CIA::setMaxage($this->maxage);
		CIA::setExpires($this->expires);
		CIA::watch($this->watch);
		if ($this->canPost) CIA::canPost();
	}
}

class loop
{
	private $loopLength = false;
	private $filter = array();

	protected function prepare() {}
	protected function next() {}

	final public function &loop()
	{
		$catchMeta = CIA::$catchMeta;
		CIA::$catchMeta = true;

		if ($this->loopLength === false) $this->loopLength = (int) $this->prepare();

		if (!$this->loopLength) $data = false;
		else
		{
			$data = $this->next();
			if ($data || is_array($data))
			{
				$data = (object) $data;
				$i = 0;
				$len = count($this->filter);
				while ($i<$len) $data = (object) call_user_func($this->filter[$i++], $data, $this);
			}
			else $this->loopLength = false;
		}

		CIA::$catchMeta = $catchMeta;

		return $data;
	}

	final public function addFilter($filter) {if ($filter) $this->filter[] = $filter;}

	final public function __toString()
	{
		$catchMeta = CIA::$catchMeta;
		CIA::$catchMeta = true;

		if ($this->loopLength === false) $this->loopLength = (int) $this->prepare();

		CIA::$catchMeta = $catchMeta;

		return (string) $this->loopLength;
	}

	final public function getLength()
	{
		return (int) $this->__toString();
	}
}

class PrivateDetection extends Exception {}
