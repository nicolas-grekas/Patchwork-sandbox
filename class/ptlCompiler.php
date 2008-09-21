<?php /*********************************************************************
 *
 *   Copyright : (C) 2007 Nicolas Grekas. All rights reserved.
 *   Email     : p@tchwork.org
 *   License   : http://www.gnu.org/licenses/agpl.txt GNU/AGPL
 *
 *   This program is free software; you can redistribute it and/or modify
 *   it under the terms of the GNU Affero General Public License as
 *   published by the Free Software Foundation, either version 3 of the
 *   License, or (at your option) any later version.
 *
 ***************************************************************************/


abstract class
{
	private

	$Xlvar = '\\{',
	$Xrvar = '\\}',

	$Xlblock = '<!--\s*',
	$Xrblock = '\s*-->',
	$Xcomment = '\\{\*.*?\*\\}',

	$Xvar = '(?:(?:[dag][-+]\d+|\\$*|[dag])?\\$)',
	$XpureVar = '[a-zA-Z_\x80-\xffffffff][a-zA-Z_\d\x80-\xffffffff]*',

	$Xblock = '[A-Z]+\b',
	$XblockEnd = 'END:',

	$Xstring = '"[^"\\\\]*(?:\\\\.[^"\\\\]*)*"|\'[^\'\\\\]*(?:\\\\.[^\'\\\\]*)*\'',
	$Xnumber,
	$XvarNconst,
	$Xmath,
	$Xexpression,
	$XfullVar,
	$Xmodifier,
	$XmodifierPipe,

	$code,
	$codeLast,
	$concat,
	$concatLast,
	$source,

	$path_idx,
	$template,

	$offset = 0,
	$blockStack = array();


	protected

	$watch,
	$mode = 'echo',
	$binaryMode = true,
	$serverMode = true,
	$closeModifier = ')';


	function __construct($binaryMode)
	{
		p::watch($this->watch);

		$this->binaryMode = $binaryMode;
		$this->Xvar .= $this->XpureVar;

		$dnum = '(?:(?:\d*\.\d+)|(?:\d+\.\d*))';
		$this->Xnumber = "-?(?:(?:\d+|$dnum)[eE][+-]?\d+|$dnum|[1-9]\d*|0[xX][\da-fA-F]+|0[0-7]*)(?!\d)";
		$this->XvarNconst = "(?<!\d)(?:{$this->Xstring}|{$this->Xnumber}|{$this->Xvar}|[dag]\\$|\\$+)";

		$this->Xmath = "\(*(?:{$this->Xnumber}|{$this->Xvar})\)*";
		$this->Xmath = "(?:{$this->Xmath}\s*[-+*\/%]\s*)*{$this->Xmath}";
		$this->Xexpression = "(?<!\d)(?:{$this->Xstring}|(?:{$this->Xmath})|[dag]\\$|\\$+|[\/~])";

		$this->Xmodifier = $this->XpureVar;
		$this->XmodifierPipe = "\\|{$this->Xmodifier}(?::(?:{$this->Xexpression})?)*";

		$this->XfullVar = "({$this->Xexpression}|{$this->Xmodifier}(?::(?:{$this->Xexpression})?)+)((?:{$this->XmodifierPipe})*)";
	}

	final public function compile($template)
	{
		$this->source = $this->load($template);

		$this->code = array('');
		$this->codeLast = 0;

		$this->makeBlocks($this->source);

		$this->offset = strlen($this->source);
		if ($this->blockStack) $this->endError('$end', array_pop($this->blockStack));

		if (!($this->codeLast%2)) $this->code[$this->codeLast] = $this->getEcho( $this->makeVar("'" . $this->code[$this->codeLast]) );

		return $this->makeCode($this->code);
	}

	final protected function getLine()
	{
		$a = substr($this->source, 0, $this->offset);

		return substr_count($a, "\n") + substr_count($a, "\r") + 1;
	}

	private function load($template, $path_idx = 0)
	{
		'.ptl' === strtolower(substr($template, -4)) && $template = substr($template, 0, -4);

		$this->template = IS_WINDOWS ? strtolower($template) : $template;
		$this->template = preg_replace("'[\\/]+'", '/', $this->template);

		$source = p::resolvePublicPath($template . '.ptl', $path_idx);

		if ($source) $template = $source;
		else
		{
			$path_idx = 0;
			$template = p::resolvePublicPath($template, $path_idx);
		}

		if (!$template) return '{$DATA}';

		$source = file_get_contents($template);
		UTF8_BOM === substr($source, 0, 3) && $source = substr($source, 3);

		if (!preg_match('//u', $source)) W("Template file {$template}:\nfile encoding is not valid UTF-8. Please convert your source code to UTF-8.");

		$source = rtrim($source);
		if (false !== strpos($source, "\r")) $source = strtr(str_replace("\r\n", "\n", $source), "\r", "\n");

		if ('.ptl' !== strtolower(substr($template, -4)))
		{
			$source = preg_replace("'(?:{$this->Xlvar}|{$this->Xlblock})'" , "{'$0'}", $source);

			return $source;
		}

		$source = preg_replace_callback("'" . $this->Xcomment . "\n?'su", array($this, 'preserveLF'), $source);
		$source = preg_replace("'({$this->Xrblock})\n'su", "\n$1", $source);
		$source = preg_replace_callback(
			"/({$this->Xlblock}(?:{$this->XblockEnd})?{$this->Xblock})((?".">{$this->Xstring}|.)*?)({$this->Xrblock})/su",
			array($this, 'autoSplitBlocks'),
			$source
		);

		if ($this->serverMode)
		{
			$source = preg_replace_callback(
				"'{$this->Xlblock}CLIENTSIDE{$this->Xrblock}.*?{$this->Xlblock}{$this->XblockEnd}CLIENTSIDE{$this->Xrblock}'su",
				array($this, 'preserveLF'),
				$source
			);

			$source = preg_replace_callback(
				"'{$this->Xlblock}({$this->XblockEnd})?SERVERSIDE{$this->Xrblock}'su",
				array($this, 'preserveLF'),
				$source
			);
		}
		else
		{
			$source = preg_replace_callback(
				"'{$this->Xlblock}SERVERSIDE{$this->Xrblock}.*?{$this->Xlblock}{$this->XblockEnd}SERVERSIDE{$this->Xrblock}'su",
				array($this, 'preserveLF'),
				$source
			);

			$source = preg_replace_callback(
				"'{$this->Xlblock}({$this->XblockEnd})?CLIENTSIDE{$this->Xrblock}'su",
				array($this, 'preserveLF'),
				$source
			);
		}

		$this->path_idx = $path_idx;
		$rx = '[-_a-zA-Z\d\x80-\xffffffff][-_a-zA-Z\d\x80-\xffffffff\.]*';
		$source = preg_replace_callback("'{$this->Xlblock}INLINE\s+($rx(?:[\\/]$rx)*)(:-?\d+)?\s*{$this->Xrblock}'su", array($this, 'INLINEcallback'), $source);

		return $source;
	}

	protected function preserveLF($m)
	{
		return str_repeat("\r", substr_count($m[0], "\n"));
	}

	protected function autoSplitBlocks($m)
	{
		$a =& $m[2];
		$a = preg_split("/({$this->Xstring})/su", $a, -1, PREG_SPLIT_DELIM_CAPTURE);

		$i = 0;
		$len = count($a);
		while ($i < $len)
		{
			$a[$i] = preg_replace("'\n\s*(?:{$this->XblockEnd})?{$this->Xblock}(?!\s*=)'su", ' --><!-- $0', $a[$i]);
			$i += 2;
		}

		return $m[1] . implode($a) . $m[3];
	}

	protected function INLINEcallback($m)
	{
#>		p::watch('debugSync');

		$template = IS_WINDOWS ? strtolower($m[1]) : $m[1];
		$template = preg_replace("'[\\/]+'", '/', $template);
		'.ptl' === substr($template, -4) && $template = substr($template, 0, -4);

		$a = $template === $this->template;
		$a = isset($m[2]) ? substr($m[2], 1) : ($a ? -1 : (PATCHWORK_PATH_LEVEL - $this->path_idx));
		$a = $a < 0 ? $this->path_idx - $a : (PATCHWORK_PATH_LEVEL - $a);

		if ($a < 0)
		{
			W("Template error: Invalid level (resolved to $a) in \"{$m[0]}\"");
			return $m[0];
		}
		else
		{
			if ($a > PATCHWORK_PATH_LEVEL) $a = PATCHWORK_PATH_LEVEL;
			return $this->load($m[1], $a);
		}
	}

	abstract protected function makeCode(&$code);
	abstract protected function addAGENT($end, $inc, &$args, $is_exo);
	abstract protected function addSET($end, $name, $type);
	abstract protected function addLOOP($end, $var);
	abstract protected function addIF($end, $elseif, $expression);
	abstract protected function addELSE($end);
	abstract protected function getEcho($str);
	abstract protected function getConcat($array);
	abstract protected function getRawString($str);
	abstract protected function getVar($name, $type, $prefix, $forceType);
	abstract protected function makeModifier($name);

	final protected function makeVar($name, $forceType = false)
	{
		$type = $prefix = '';
		if ("'" === $name[0])
		{
			$type = "'";
			$name = $this->filter(substr($name, 1));
		}
		else if (false !== $pos = strrpos($name, '$'))
		{
			$type = $name[0];
			$prefix = substr($name, 1, '$' === $type ? $pos : $pos-1);
			$name = substr($name, $pos+1);
		}
		else $type = '';

		return $this->getVar($name, $type, $prefix, $forceType);
	}

	final protected function pushText($a)
	{
		if ('concat' === $this->mode)
		{
			if ($this->concatLast % 2) $this->concat[++$this->concatLast] = $a;
			else $this->concat[$this->concatLast] .= $a;
		}
		else
		{
			if ($this->codeLast % 2) $this->code[++$this->codeLast] = $a;
			else $this->code[$this->codeLast] .= $a;
		}
	}

	final protected function pushCode($a)
	{
		if ($this->codeLast % 2) $this->code[$this->codeLast] .= $a;
		else
		{
			$this->code[$this->codeLast] = $this->getEcho( $this->makeVar("'" . $this->code[$this->codeLast]) );
			$this->code[++$this->codeLast] = $a;
		}
	}


	private function filter($a)
	{
		if (false !== strpos($a, "\r")) $a = str_replace("\r", '', $a);

		return $this->binaryMode ? $a : preg_replace_callback("/\s{2,}/su", array(__CLASS__, 'filter_callback'), $a);
	}

	private function makeBlocks($a)
	{
		$a = preg_split("/({$this->Xlblock}{$this->Xblock}(?".">{$this->Xstring}|.)*?{$this->Xrblock})/su", $a, -1, PREG_SPLIT_OFFSET_CAPTURE | PREG_SPLIT_DELIM_CAPTURE);

		$this->makeVars($a[0][0]);

		$i = 1;
		$len = count($a);
		while ($i < $len)
		{
			$this->offset = $a[$i][1];
			$this->compileBlock($a[$i++][0]);
			$this->makeVars($a[$i++][0]);
		}
	}

	private function makeVars(&$a)
	{
		$a = preg_split("/{$this->Xlvar}{$this->XfullVar}{$this->Xrvar}/su", $a, -1, PREG_SPLIT_DELIM_CAPTURE);

		$this->pushText($a[0]);

		$i = 1;
		$len = count($a);
		while ($i < $len)
		{
			$this->compileVar($a[$i++], $a[$i++]);
			$this->pushText($a[$i++]);
		}
	}

	private function compileBlock(&$a)
	{
		$blockname = $blockend = false;

		if (preg_match("/^{$this->Xlblock}{$this->XblockEnd}({$this->Xblock}).*?{$this->Xrblock}$/su", $a, $block))
		{
			$blockname = $block[1];
			$block = false;
			$blockend = true;
		}
		else if (preg_match("/^{$this->Xlblock}({$this->Xblock})(.*?){$this->Xrblock}$/su", $a, $block))
		{
			$blockname = $block[1];
			$block = trim($block[2]);
		}

		if (false !== $blockname)
		{
			switch ($blockname)
			{
			case 'EXOAGENT':
			case 'AGENT':
				$is_exo = 'EXOAGENT' === $blockname;

				if (preg_match("/^({$this->Xstring}|{$this->Xvar})(?:\s+{$this->XpureVar}\s*=\s*(?:{$this->XvarNconst}))*$/su", $block, $block))
				{
					$inc = $this->evalVar($block[1]);

					if ("''" !== $inc)
					{
						$args = array();
						if (preg_match_all("/\s+({$this->XpureVar})\s*=\s*({$this->XvarNconst})/su", $block[0], $block))
						{
							$i = 0;
							$len = count($block[0]);
							while ($i < $len)
							{
								$args[ $block[1][$i] ] = $this->evalVar($block[2][$i]);
								$i++;
							}
						}

						if (!$this->addAGENT($blockend, $inc, $args, $is_exo)) $this->pushText($a);
					}
					else $this->pushText($a);
				}
				else $this->pushText($a);
				break;

			case 'SET':
				if (preg_match("/^([dag]|\\$*)\\$({$this->XpureVar})$/su", $block, $block))
				{
					$type = $block[1];
					$block = $block[2];

					if ($this->addSET($blockend, $block, $type)) $this->blockStack[] = $blockname;
					else $this->pushText($a);
				}
				else if ($blockend)
				{
					if ($this->addSET($blockend, '', ''))
					{
						$block = array_pop($this->blockStack);
						if ($block !== $blockname) $this->endError($blockname, $block);
					}
					else $this->pushText($a);
				}
				else $this->pushText($a);

				break;

			case 'LOOP':

				$block = preg_match("/^{$this->Xexpression}$/su", $block, $block)
					? preg_replace_callback("/{$this->XvarNconst}/su", array($this, 'evalVar_callback'), $block[0])
					: '';

				$block = preg_replace("/\s+/su", '', $block);

				if (!$this->addLOOP($blockend, $block)) $this->pushText($a);
				else if ($blockend)
				{
					$block = array_pop($this->blockStack);
					if ($block !== $blockname) $this->endError($blockname, $block);
				}
				else $this->blockStack[] = $blockname;
				break;

			case 'IF':
			case 'ELSEIF':
				if ($blockend)
				{
					if (!$this->addIF(true, 'ELSEIF' === $blockname, $block)) $this->pushText($a);
					else
					{
						$block = array_pop($this->blockStack);
						if ($block !== $blockname) $this->endError($blockname, $block);
					}
					break;
				}

				$block = preg_split(
					"/({$this->Xstring}|{$this->Xvar})/su",
					$block, -1, PREG_SPLIT_DELIM_CAPTURE
				);
				$testCode = preg_replace("'\s+'u", '', $block[0]);
				$var = array();

				$i = $j = 1;
				$len = count($block);
				while ($i < $len)
				{
					$var['$a' . $j . 'b'] = $block[$i++];
					$testCode .= '$a' . $j++ . 'b ' . preg_replace("'\s+'u", '', $block[$i++]);
				}

				$testCode = preg_replace('/\s+/su', ' ', $testCode);
				$testCode = strtr($testCode, '#[]{}^~?:,', ';;;;;;;;;;');
				$testCode = str_replace(
					array('&&' , '||' , '&', '|', '<>'),
					array('#a#', '#o#', ';', ';', ';' ),
					$testCode
				);
				$testCode = preg_replace(
					array('/<<+/', '/>>+/', '/[a-zA-Z_0-9\xf7-\xff]\(/'),
					array(';'    , ';'    , ';'),
					$testCode
				);
				$testCode = str_replace(
					array('#a#', '#o#'),
					array('&&' , '||'),
					$testCode
				);

				$i = @eval("($testCode);");
				if (false !== $i) while (--$j) if (isset(${'a'.$j.'b'})) $i = false;

				if (false !== $i)
				{
					$block = preg_split('/(\\$a\db) /su', $testCode, -1, PREG_SPLIT_DELIM_CAPTURE);

					$expression = $block[0];

					$i = 1;
					$len = count($block);
					while ($i < $len)
					{
						$expression .= $this->evalVar($var[ $block[$i++] ], false, 'string');
						$expression .= $block[$i++];
					}

					if (!$this->addIF(false, 'ELSEIF' === $blockname, $expression)) $this->pushText($a);
					else if ('ELSEIF' !== $blockname) $this->blockStack[] = $blockname;
				}
				else $this->pushText($a);
				break;

			default:
				if (!(method_exists($this, 'add'.$blockname) && $this->{'add'.$blockname}($blockend, $block))) $this->pushText($a);
			}
		}
		else $this->pushText($a);
	}

	private function compileVar($var, $pipe)
	{
		$detail = array();

		preg_match_all("/({$this->Xexpression}|{$this->Xmodifier}|(?<=:)(?:{$this->Xexpression})?)/su", $var, $match);
		$detail[] = $match[1];

		preg_match_all("/{$this->XmodifierPipe}/su", $pipe, $match);
		foreach ($match[0] as &$match)
		{
			preg_match_all("/(?:^\\|{$this->Xmodifier}|:(?:{$this->Xexpression})?)/su", $match, $match);
			foreach ($match[0] as &$j) $j = ':' === $j ? "''" : substr($j, 1);
			unset($j);
			$detail[] = $match[0];
		}

		$Estart = '';
		$Eend = '';

		$i = count($detail);
		while (--$i)
		{
			class_exists('pipe_' . $detail[$i][0]) || W("Template warning: pipe_{$detail[$i][0]} does not exist");
			$Estart .= $this->makeModifier($detail[$i][0]) . '(';
			$Eend = $this->closeModifier . $Eend;

			$j = count($detail[$i]);
			while (--$j) $Eend = ',' . $this->evalVar($detail[$i][$j], true) . $Eend;
		}

		if (isset($detail[0][1]))
		{
			$Eend = $this->closeModifier . $Eend;

			$j = count($detail[0]);
			while (--$j) $Eend = ',' . $this->evalVar($detail[0][$j], true) . $Eend;

			$Eend[0] = '(';
			class_exists('pipe_' . $detail[0][0]) || W("Template warning: pipe_{$detail[0][0]} does not exist");
			$Estart .= $this->makeModifier($detail[0][0]);
		}
		else $Estart .= $this->evalVar($detail[0][0], true);

		if ("'" === $Estart[0])
		{
			$Estart = $this->getRawString($Estart);
			$this->pushText($Estart);
		}
		else if ('concat' === $this->mode)
		{
			$this->concat[++$this->concatLast] = $Estart . $Eend;
		}
		else $this->pushCode( $this->getEcho($Estart . $Eend) );
	}

	private function evalVar($a, $translate = false, $forceType = false)
	{
		if ( '' === $a) return "''";
		if ('~' === $a) $a = 'g$__BASE__';
		if ('/' === $a) $a = 'g$__HOST__';

		if ('"' === $a[0] || "'" === $a[0])
		{
			$b = '"' === $a[0];

			if (!$b) $a = '"' . substr(preg_replace('/(?<!\\\\)((?:\\\\\\\\)*)"/su', '$1\\\\"', $a), 1, -1) . '"';
			$a = preg_replace("/(?<!\\\\)\\\\((?:\\\\\\\\)*)'/su", '$1\'', $a);
			$a = preg_replace('/(?<!\\\\)((\\\\?)(?:\\\\\\\\)*)\\$/su', '$1$2\\\\$', $a);
			$a = eval("return $a;");

			if ($b && '' !== trim($a))
			{
				if ($translate)
				{
					$a = TRANSLATOR::get($a, p::__LANG__(), false);
#>					p::watch('debugSync');
				}
				else
				{
					$this->mode = 'concat';
					$this->concat = array('');
					$this->concatLast = 0;

					$this->makeVars($a);

					if (!$this->concatLast)
					{
						$this->concat[0] = TRANSLATOR::get($this->concat[0], p::__LANG__(), false);
#>						p::watch('debugSync');
					}

					for ($i = 0; $i<=$this->concatLast; $i+=2)
					{
						if ('' !== $this->concat[$i]) $this->concat[$i] = $this->makeVar("'" . $this->concat[$i]);
						else unset($this->concat[$i]);

					}

					$this->mode = 'echo';
					return count($this->concat)>1 ? $this->getConcat($this->concat) : current($this->concat);
				}
			}

			$a = "'" . $a;
		}
		else if (preg_match("/^{$this->Xnumber}$/su", $a)) $a = eval("return \"'\" . $a;");
		else if (!preg_match("/^(?:{$this->Xvar}|[dag]\\$|\\$+)$/su", $a))
		{
			$a = preg_split("/({$this->Xvar}|{$this->Xnumber})/su", $a, -1, PREG_SPLIT_DELIM_CAPTURE);

			$i = 1;
			$len = count($a);
			while ($i < $len)
			{
				$a[$i-1] = trim($a[$i-1]);

				$b = $i > 1 && '-' === $a[$i][0] && '' === $a[$i-1];

				$a[$i] = $this->evalVar($a[$i], false, 'number');

				if ($b && '0' === $a[$i]) $a[$i-1] = '-';

				$i += 2;
			}

			$a = implode($a);
			return $a;
		}

		return $this->makeVar($a, $forceType);
	}

	protected static function filter_callback($m) {return false === strpos($m[0], "\n") ? ' ' : "\n";}
	private function evalVar_callback($m) {return $this->evalVar($m[0]);}

	private function endError($unexpected, $expected)
	{
		W("Template Parse Error: Unexpected END:$unexpected" . ($expected ? ", expecting END:$expected" : '') . " line " . $this->getLine());
	}
}
