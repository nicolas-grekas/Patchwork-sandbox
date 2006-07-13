<?php

function jsquote($a, $addDelim = true, $delim = "'")
{
	if ((string) $a === (string) ($a-0)) return $a-0;

	$a = str_replace(
		array("\r\n", "\r", '\\'  , "\n", '</' ,        $delim),
		array("\n"  , "\n", '\\\\', '\n', '<\\/', '\\' . $delim),
		$a
	);

	if ($addDelim) $a = $delim . $a . $delim;

	return $a;
}

function jsquoteRef(&$a) {$a = jsquote($a);}

class
{
	public static $cachePath = 'zcache/';
	public static $agentClass;
	public static $catchMeta = false;

	protected static $host;
	protected static $lang = '__';
	protected static $home;
	protected static $uri;

	protected static $handlesOb;
	protected static $metaInfo;
	protected static $metaPool = array();
	protected static $isGroupStage = true;

	protected static $maxage = false;
	protected static $private = false;
	protected static $expires = 'auto';
	protected static $watchTable = array();
	protected static $headers;

	protected static $cia;
	protected static $redirectUrl = false;
	protected static $agentClasses = '';
	protected static $sessionStarted = false;
	protected static $cancelled = false;
	protected static $privateDetectionMode = false;
	protected static $detectXSJ = false;

	public static function start()
	{
		// Stupid Zend Engine with PHP 5.0.x ...
		// Static vars assigned in the class declaration are not accessible from an instance of a derived class.
		// The workaround is to assign them at run time...
		self::$handlesOb = false;
		self::$headers = array();

		$cachePath = resolvePath(self::$cachePath);
		self::$cachePath = ($cachePath == self::$cachePath ? $GLOBALS['cia_paths'][count($GLOBALS['cia_paths']) - 2] . '/' : '') . $cachePath;

		if (DEBUG) self::$cia = new debug_CIA;
		else self::$cia = new CIA;

		self::setLang($_SERVER['CIA_LANG'] ? $_SERVER['CIA_LANG'] : substr($GLOBALS['CONFIG']['lang_list'], 0, 2));

		if (htmlspecialchars(self::$home) != self::$home)
		{
			E('Fatal error: illegal character found in CIA::$home');
			exit;
		}

		if (isset($_GET['T$'])) self::$private = true;
	}

	public static function sessionStart($private = true)
	{
		if ($private) self::setGroup('private');

		if (!self::$sessionStarted)
		{
			self::$sessionStarted = true;
			@session_start();
		}
	}

	public static function cancel()
	{
		self::$cancelled = true;
		ob_end_flush();
	}

	public static function setLang($new_lang)
	{
		$lang = self::$lang;
		self::$lang = $new_lang;

		self::$home = explode('__', $_SERVER['CIA_HOME'], 2);
		self::$home = implode($new_lang, self::$home);

		self::$host = substr(self::$home, 0, strpos(self::$home, '/', 8)+1);
		self::$uri = self::$host . substr($_SERVER['REQUEST_URI'], 1);

		return $lang;
	}

	public static function __HOST__() {return self::$host;}
	public static function __LANG__() {return self::$lang;}
	public static function __HOME__() {return self::$home;}
	public static function __URI__()  {return self::$uri ;}

	public static function home($url)
	{
		if (!preg_match("'^https?://'", $url))
		{
			if ('/' != substr($url, 0, 1)) $url = self::$home . $url;
			else $url = self::$host . substr($url, 1);
		}

		return $url;
	}

	/**
	 * Replacement for PHP's header() function
	 */
	public static function header($string)
	{
		if (!self::$cancelled && (
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

		if (!self::$privateDetectionMode)
		{
			if ('content-type' == $name && false !== strpos(strtolower($string), 'javascript'))
			{
				if (self::$private) self::preventXSJ();

				self::$detectXSJ = true;
			}

			self::$headers[$name] = $string;
			header($string);
		}
	}

	/**
	 * Redirect the web browser to an other GET request
	 */
	public static function redirect($url = '', $exit = true)
	{
		if (self::$privateDetectionMode)
		{
			if ($exit) throw new Exception;

			return;
		}

		$url = (string) $url;

		self::$redirectUrl = '' === $url ? '' : (preg_match("'^([^:/]+:/|\.+)?/'i", $url) ? $url : (self::$home . ('index' == $url ? '' : $url)));

		if ($exit) exit;
	}

	public static function openMeta($agentClass, $is_trace = true)
	{
		self::$isGroupStage = true;

		self::$agentClass = $agentClass;
		if ($is_trace) self::$agentClasses .= '*' . self::$agentClass;

		$default = array(false, array(), false, array(), array(), false, self::$agentClass);

		self::$catchMeta = true;

		self::$metaPool[] =& $default;
		self::$metaInfo =& $default;
	}

	public static function closeGroupStage()
	{
		self::$isGroupStage = false;

		return self::$metaInfo[1];
	}

	public static function closeMeta()
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

	/**
	 * Controls the Cache's max age.
	 */
	public static function setMaxage($maxage)
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

	/**
	 * Controls the Cache's groups.
	 */
	public static function setGroup($group)
	{
		if ('public' == $group) return;

		$group = array_diff((array) $group, array('public'));

		if (!$group) return;

		if (self::$privateDetectionMode) throw new PrivateDetection;
		else if (self::$detectXSJ) self::preventXSJ();

		self::$private = true;

		if (self::$catchMeta)
		{
			$a =& self::$metaInfo[1];

			if (count($a) == 1 && 'private' == $a[0]) return;

			if (in_array('private', $group)) $a = array('private');
			else
			{
				$b = $a;

				$a = array_unique( array_merge($a, $group) );
				sort($a);

				if ($b != $a && !self::$isGroupStage)
				{
					if (DEBUG) E('Miss-conception: CIA::setGroup() is called in ' . self::$agentClass . '->compose( ) rather than in ' . self::$agentClass . '->control(). Cache is now disabled for this agent.');

					$a = array('private');
				}
			}
		}
	}

	/**
	 * Controls the Cache's expiration mechanism.
	 */
	public static function setExpires($expires)
	{
		if (!self::$privateDetectionMode) if ('auto' == self::$expires || 'ontouch' == self::$expires) self::$expires = $expires;

		if (self::$catchMeta) self::$metaInfo[2] = $expires;
	}

	public static function watch($watch)
	{
		if (self::$catchMeta) self::$metaInfo[3] = array_merge(self::$metaInfo[3], (array) $watch);
	}

	public static function canPost()
	{
		if (self::$catchMeta) self::$metaInfo[5] = true;
	}

	public static function string($a)
	{
		return is_object($a) ? $a->__toString() : (string) $a;
	}

	public static function uniqid() {return md5( uniqid(mt_rand(), true) );}

	/**
	 *  Returns the hash of $pwd if this hash match $crypted_pwd or if $crypted_pwd is not supplied. Else returns false.
	 */
	public static function pwd($pwd, $crypted_pwd = false)
	{
		static $saltLen = 4;

		if ($crypted_pwd !== false)
		{
			$salt = substr($crypted_pwd, 0, $saltLen);
			if ($salt . md5($pwd . $salt) != $crypted_pwd) return false;
		}

		$a = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZ_abcdefghijklmnopqrstuvwxyz';
		$b = strlen($a) - 1;

		$salt = '';
		do $salt .= $a{ mt_rand(0, $b) }; while (--$saltLen);

		return $salt . md5($pwd . $salt);
	}

	/**
	 * Revokes every agent watching $message
	 */
	public static function touch($message)
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

			if (DEBUG) E("CIA::touch('$message'): $i file(s) deleted.");
		}
	}

	/**
	 * Like mkdir(), but works with multiple level of inexistant directory
	 */
	public static function makeDir($dir)
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
		else if (!('WIN' == substr(PHP_OS, 0, 3) && substr($dir[0], -1) == ':')) $dir[0] = './' . $dir[0];

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

	/**
	 * Sort of recursive version of rmdir()
	 */
	public static function delDir($dir, $rmdir)
	{
		$d = @opendir($dir);
		if (!$d) return;

		while (false !== ($file = readdir($d)))
		{
			if ($file!='.' && $file!='..')
			{
				$file = "$dir/$file";
				if(is_dir($file)) self::delDir($file, $rmdir);
				else @unlink($file);
			}
		}

		closedir($d);
		if ($rmdir) @rmdir($dir); // Time consuming
	}

	public static function fopenX($file)
	{
		self::makeDir($file);

		if ($h = @fopen($file, 'x+b'))
		{
			flock($h, LOCK_EX+LOCK_NB, $w);

			if ($w) fclose($h);
			else return $h;
		}

		return false;
	}

	/**
	 * Creates the full directory path to $filename, then writes $data into this file
	 */
	public static function writeFile($filename, &$data, $Dmtime = 0)
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

			if ('WIN' == substr(PHP_OS, 0, 3)) @unlink($filename);
			rename($tmpname, $filename);

			if ($Dmtime) touch($filename, $_SERVER['REQUEST_TIME'] + $Dmtime);

			return true;
		}
		else return false;
	}


	/*
	 * The following methods are used internally, mainly by the IA_* class
	 */

	protected static function getCachePath($filename, $extension, $key = '')
	{
		if (''!==(string)$extension) $extension = '.' . $extension;

		$hash = md5($filename . $extension . '.'. $key);
		$hash = $hash{0} . '/' . $hash{1} . '/' . substr($hash, 2);

		$filename = rawurlencode(str_replace('/', '.', $filename));
		$filename = substr($filename, 0, 224 - strlen($extension));

		return self::$cachePath . $hash . '.' . $filename . $extension;
	}

	public static function getContextualCachePath($filename, $extension, $key = '')
	{
		return self::getCachePath($filename, $extension, self::$home .'-'. self::$lang .'-'. DEBUG .'-'. CIA_PROJECT_PATH .'-'. $key);
	}

	public static function ciaLog($message, $is_end = false, $html = true)
	{
		if (isset(self::$cia)) return self::$cia->log($message, $is_end, $html);
		else trigger_error(serialize($message));
	}

	public static function resolveAgentClass($agent, &$args)
	{
		static $resolvedCache = array();


		if (isset($resolvedCache[$agent])) return 'agent_' . str_replace('/', '_', $agent);


		$agent = preg_replace("'/(\.?/)+'", '/', '/' . $agent . '/');

		do $agent = preg_replace("'[^/]+/\.\./'", '/', $a = $agent);
		while ($a != $agent);

		$agent = substr($agent, 1, -1);
		$agent = preg_replace("'^(\.\.?/)+'", '', $agent);

		preg_match("'^((?:[a-z0-9]+(?:[-_][a-z0-9]+)*(?:/|$))*)(.*?)$'iu", $agent, $agent);

		$param = '' !== $agent[2] ? explode('/', $agent[2]) : array();
		$agent = $agent[1];

		if ('/' == substr($agent, -1)) $agent = substr($agent, 0, -1);

		if ('' !== $agent)
		{
			$potentialAgent = preg_replace("'[-_](.)'e", "strtoupper('$1')", $agent);
		}
		else $potentialAgent = $agent = 'index';

		$lang = self::$lang;
		$createTemplate = true;

		while (1)
		{
			if (isset($resolvedCache[$potentialAgent]))
			{
				$createTemplate = false;
				break;
			}

			$path = "class/agent/{$potentialAgent}.php";
			$p_th = resolvePath($path);
			if ($path != $p_th)
			{
				$createTemplate = false;
				break;
			}


			$path = "public/{$lang}/{$potentialAgent}.tpl";
			if ($path != resolvePath($path)) break;

			$path = "public/__/{$potentialAgent}.tpl";
			if ($path != resolvePath($path)) break;


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

		if ($createTemplate) eval('class ' . $agent . ' extends agent{protected $maxage=-1;protected $watch=array(\'public/templates\');}');

		return $agent;
	}

	public static function agentArgv($agent)
	{
		// get declared arguments in $agent::$argv public property
		$args = get_class_vars($agent);
		$args =& $args['argv'];

		if (is_array($args)) array_walk($args, array('self', 'stripArgv'));
		else $args = array();

		// autodetect private data for antiXSJ
		$cache = self::getContextualCachePath('antiXSJ.' . $agent, 'txt');

		if ($h = self::fopenX($cache))
		{
			$private = '';

			self::$privateDetectionMode = true;

			try
			{
				$agent = new $agent;
				$d = (object) $agent->compose((object) array());
				$agent->getTemplate();

				self::executeLoops($d);

				$agent->metaCompose();
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
		else if (filesize($cache)) $args[] = 'T$';

		return $args;
	}

	protected static function executeLoops($d)
	{
		foreach ($d as $k => &$v) if ($v instanceof loop) while ($k = $v->loop()) self::executeLoops($k);
	}

	public static function resolveAgentTrace($agent)
	{
		static $cache = array();

		if (isset($cache[$agent])) return $cache[$agent];
		else $cache[$agent] =& $trace;

		$args = array();
		$HOME = $home = CIA::__HOME__();
		$agent = self::home($agent);
		$keys = false;
		$s = '\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\'';
		$s = "/w\.k\((-?[0-9]+),($s),($s),($s),\[((?:$s(?:,$s)*)?)\]\)/su";

		if (
			   0 === strpos($agent, $HOME)
			&& is_callable('exec')
			&& (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] ? !extension_loaded('openssl') : false)
			&& $keys = @$GLOBALS['CONFIG']['php']
		)
		{
			$keys = $keys . ' -q ' . implode(' ', array_map('escapeshellarg', array(
				resolvePath('getTrace.php'),
				resolvePath('config.php'),
				$_SERVER['CIA_HOME'],
				CIA::__LANG__(),
				substr($agent, strlen($HOME)),
				(int) @$_SERVER['HTTPS']
			)));

			$keys = exec($keys);

			if (!preg_match($s, $keys, $keys)) $keys = false;
		}

		if (!$keys)
		{
			require_once 'HTTP/Request.php';
			$agent = implode(CIA::__LANG__(), explode('__', $agent, 2));
			$keys = new HTTP_Request($agent . '?k$=');
			$keys->sendRequest();
			$keys = $keys->getResponseBody();

			if (!preg_match($s, $keys, $keys))
			{
				E('Error while getting meta info data for ' . htmlspecialchars($agent));
				exit;
			}
		}

		$CIApID = (int) $keys[1];
		$home = stripcslashes(substr($keys[2], 1, -1));
		$home = preg_replace("'__'", CIA::__LANG__(), $home, 1);
		$agent = stripcslashes(substr($keys[3], 1, -1));
		$a = stripcslashes(substr($keys[4], 1, -1));
		$keys = eval('return array(' . $keys[5] . ');');

		if ('' !== $a)
		{
			$args['__0__'] = $a;

			$i = 0;
			foreach (explode('/', $a) as $a) $args['__' . ++$i . '__'] = $a;
		}

		if ($home == $HOME) $CIApID = $home = false;
		else self::watch('foreignTrace');

		return $trace = array($CIApID, $home, $agent, $keys, $args);
	}

	protected static function stripArgv(&$a, $k)
	{
		if (is_string($k)) $a = $k;

		$b = strpos($a, ':');
		if (false !== $b) $a = substr($a, 0, $b);
	}

	public static function agentCache($agentClass, $keys, $type, $group = false)
	{
		if (false === $group) $group = self::$metaInfo[1];
		$keys = serialize(array($keys, $group));

		return self::getContextualCachePath($agentClass, $type . '.php', $keys);
	}

	public static function delCache()
	{
		self::touch('');
		self::delDir(self::$cachePath, false);
	}

	public static function writeWatchTable($message, $file)
	{
		$file =  "++\$i;unlink('" . str_replace(array('\\',"'"), array('\\\\',"\\'"), $file) . "');\n";

		foreach (array_unique((array) $message) as $message)
		{
			if (self::$catchMeta) self::$metaInfo[3][] = $message;

			$message = preg_split("'[\\\\/]+'u", $message, -1, PREG_SPLIT_NO_EMPTY);
			$message = array_map('rawurlencode', $message);
			$message = implode('/', $message);
			$message = str_replace('.', '%2E', $message);

			self::$watchTable[] = $path = self::getCachePath('watch/' . $message, 'php');

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
					$file = "include '" . str_replace(array('\\',"'"), array('\\\\',"\\'"), $path) . "';\n";

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

	protected static function preventXSJ()
	{
		if (!CIA_TOKEN_MATCH)
		{
			self::setMaxage(0);
			if (self::$catchMeta) self::$metaInfo[1] = array('private');

			if (CIA_DIRECT)
			{
				$a = '';

				$cache = self::getContextualCachePath('antiXSJ.' . self::$agentClass, 'txt');

				self::makeDir($cache);

				$h = fopen($cache, 'a+b');
				flock($h, LOCK_EX);
				fseek($h, 0, SEEK_END);
				if (!ftell($h))
				{
					CIA::touch('CIApID');
					CIA::touch('public/templates/js');

					fwrite($h, $a = '1', 1);
					touch('config.php');
				}
				fclose($h);

				throw new PrivateDetection($a);
			}

			E('Potential Cross Site JavaScript. Stopping !');
			E($_SERVER); E($_POST); E($_COOKIE);

			exit;
		}
	}


	/*
	* CIA object
	*/

	protected $has_error = false;

	function __construct()
	{
		self::header('Content-Type: text/html; charset=UTF-8');
		set_error_handler(array($this, 'error_handler'));
		register_shutdown_function(array($this, 'shutdown'));
		ob_start(array($this, 'ob_handler'));
	}

	function shutdown()
	{
		if (self::$sessionStarted) session_write_close();
		DB(true);
	}

	function &ob_handler(&$buffer)
	{
		self::$handlesOb = true;
		chdir(CIA_PROJECT_PATH);

		if (self::$redirectUrl !== false)
		{
			if (CIA_DIRECT)
			{
				$buffer = 'location.replace(' . (self::$redirectUrl !== ''
					? "'" . addslashes(self::$redirectUrl) . "'"
					: 'location') . ')';
			}
			else
			{
				header('HTTP/1.x 302 Found');
				header('Location: ' . (self::$redirectUrl !== '' ? self::$redirectUrl : $_SERVER['REQUEST_URI']));

				$buffer = '';
			}

			header('Content-Length: ' . strlen($buffer));
			@ini_set('zlib.output_compression', false);

			self::$handlesOb = false;

			return $buffer;
		}


		if (self::$cancelled)
		{
			self::$handlesOb = false;
			return $buffer;
		}

		$is304 = false;

		if (!CIA_POSTING && $buffer !== '')
		{
			if (!self::$maxage) self::$maxage = 0;


			/* ETag / Last-Modified validation */

			$meta = self::$maxage . "\n"
				. self::$private . "\n"
				. implode("\n", self::$headers);

			$ETag = substr(md5($buffer .'-'. self::$expires .'-'. $meta), 0, 8);
			$ETag = hexdec($ETag);
			if ($ETag > 2147483647) $ETag -= 2147483648;

			$LastModified = gmdate('D, d M Y H:i:s \G\M\T', $ETag);
			$ETag = dechex($ETag);


			$is304 = @$_SERVER['HTTP_IF_NONE_MATCH'] == $ETag || 0===strpos(@$_SERVER['HTTP_IF_MODIFIED_SINCE'], $LastModified);


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


			if ($is304)
			{
				$buffer = '';
				@ini_set('zlib.output_compression', false);
				header('HTTP/1.x 304 Not Modified');
			}
			else
			{
				header('ETag: ' . $ETag);
				header('Last-Modified: ' . $LastModified);
			}

			header('Expires: ' . gmdate('D, d M Y H:i:s \G\M\T', $_SERVER['REQUEST_TIME'] + self::$maxage));
			header('Cache-Control: max-age=' . self::$maxage . (self::$private ? ',private,must' : ',public,proxy') . '-revalidate');
		}


		if ('HEAD' == $_SERVER['REQUEST_METHOD']) $buffer = '';


		// Setup gzip compression

		if (!$is304) switch (substr(self::$headers['content-type'], 14))
		{
			case 'image/png':
			case 'image/gif':
			case 'image/jpeg':
				header('Content-Length: ' . strlen($buffer));
				@ini_set('zlib.output_compression', false);
				break;

			default:
				if (!ini_get('zlib.output_compression')) $buffer = ob_gzhandler($buffer, 5);
				break;
		}

		self::$handlesOb = false;
		return $buffer;
	}

	function error_handler($code, $message, $file, $line, $context)
	{
		if (!error_reporting()
			|| ((E_NOTICE == $code || E_STRICT == $code) && 0!==strpos($file, end($GLOBALS['cia_paths'])))
			|| (E_WARNING == $code && false !== stripos($message, 'safe mode'))
		) return;
		$this->has_error = true;
		require resolvePath('error_handler.php');
	}
}

class agent
{
	const binary = false;

	public $argv = array();

	protected $template;

	protected $maxage  = 0;
	protected $expires = 'auto';
	protected $canPost = false;
	protected $watch = array();

	function control() {}
	function compose($o) {return $o;}
	function getTemplate()
	{
		return isset($this->template) ? $this->template : str_replace('_', '/', substr(get_class($this), 6));
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

			$b = (string) @$args[$key];

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
