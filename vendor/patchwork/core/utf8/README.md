Patchwork UTF-8
===============

Patchwork UTF-8 provides both a portability layer for Unicode handling in PHP
and a class that mirrors the quasi complete set of native string functions,
enhanced to UTF-8 [grapheme clusters](http://unicode.org/reports/tr29/) awareness.

Portability
-----------

Unicode handling in PHP is best performed using a combo of `mbstring`, `iconv`,
`intl` and `pcre` with the `u` flag enabled. But when an application is expected
to run on many servers, you should be aware that these 4 extensions are not
always enabled.

Patchwork UTF-8 provides pure PHP implementations for 3 of those 4 extensions.
Here is the set of portability-fallbacks that are currently implemented:

- *utf8_encode, utf8_decode*,
- `mbstring`: *mb_convert_encoding, mb_decode_mimeheader, mb_encode_mimeheader,
  mb_convert_case, mb_internal_encoding, mb_list_encodings, mb_strlen,
  mb_strpos, mb_strrpos, mb_strtolower, mb_strtoupper, mb_substitute_character,
  mb_substr, mb_stripos, mb_stristr, mb_strrchr, mb_strrichr, mb_strripos,
  mb_strstr*,
- `iconv`: *iconv, iconv_mime_decode, iconv_mime_decode_headers,
  iconv_get_encoding, iconv_set_encoding, iconv_mime_encode, ob_iconv_handler,
  iconv_strlen, iconv_strpos, iconv_strrpos, iconv_substr*,
- `intl`: *Normalizer, grapheme_extract, grapheme_stripos, grapheme_stristr,
  grapheme_strlen, grapheme_strpos, grapheme_strripos, grapheme_strrpos,
  grapheme_strstr, grapheme_substr*.

`pcre` compiled with unicode support is currently required.

Patchwork\Utf8
--------------

[Grapheme clusters](http://unicode.org/reports/tr29/) should always be
considered when working with generic Unicode strings. The `Patchwork\Utf8`
class implements the quasi-complete set of native string functions that need
UTF-8 grapheme clusters awareness. Function names, arguments and behavior
carefully replicates native PHP string functions so that usage is very easy.

Some more functions are also provided to help handling UTF-8 strings:

- *isUtf8()*: checks if a string contains well formed UTF-8 data,
- *toAscii()*: generic UTF-8 to ASCII transliteration,
- *strtocasefold()*: unicode transformation for caseless matching,
- *strtonatfold()*: generic case sensitive transformation for collation matching
- *getGraphemeClusters()*: splits a string to an array of grapheme clusters

Mirrored string functions are:
*strlen, substr, strpos, stripos, strrpos, strripos, strstr, stristr, strrchr,
strrichr, strtolower, strtoupper, wordwrap, chr, count_chars, ltrim, ord, rtrim,
trim, str_ireplace, str_pad, str_shuffle, str_split, str_word_count, strcmp,
strnatcmp, strcasecmp, strnatcasecmp, strncasecmp, strncmp, strcspn, strpbrk,
strrev, strspn, strtr, substr_compare, substr_count, substr_replace, ucfirst,
lcfirst, ucwords*.
Missing are *printf*-family functions and *number_format*.

Usage
-----

Including the `bootup.utf8.php` file is the easiest way to enable the
portability layer and configure PHP for an UTF-8 aware and portable application.

Classes are named following PSR-0 autoloader interoperability recommandations,
so other loading scheme are easy to implement.

The `Patchwork\Utf8` class exposes its features through static methods. Just
add a `use Patchwork\Utf8 as u;` at the beginning of your files, then when UTF-8
awareness is required, prefix the string function by `u::`:
`echo strlen("déjà");` may become `echo u::strlen("déjà");` eg.

Just run `phpunit` in the `tests/` directory to see the code in action.

Make sure that you are confident about using UTF-8 by reading
[Character Sets / Character Encoding Issues](http://www.phpwact.org/php/i18n/charsets)
and [Handling UTF-8 with PHP](http://www.phpwact.org/php/i18n/utf-8).

You should also get familar with the concept of
[Unicode Normalization](http://en.wikipedia.org/wiki/Unicode_equivalence) and
[Grapheme Clusters](http://unicode.org/reports/tr29/).

In particular, do not blindly replace all use of PHP's string functions. Most of
the time you will not need to, and you will be introducing a significant
performance overhead to your application.

Most of the functions here are not operating defensively, mainly for performance
reasons. For example there is no extensive parameter checking and it is assumed
that well formed UTF-8 is fed. You should screen input on the *outer perimeter*
with help from functions like `Patchwork\Utf8::isUtf8()`.

When dealing with badly formed UTF-8, you should not to try to fix it.
Instead, consider it as ISO-8859-1 and use `utf8_encode()` to get an UTF-8
string. Don't forget also to choose one unicode normalization form at stick to
it. NFC is the most in use today.

Licensing
---------

Patchwork\Utf8 is free software; you can redistribute it and/or modify it under
the terms of the (at your option):
- [Apache License v2.0](http://apache.org/licenses/LICENSE-2.0.txt), or
- [GNU General Public License v2.0](http://gnu.org/licenses/gpl-2.0.txt).

Unicode handling requires tedious work to be implemented and maintained on the
long run. As such, contributions such as unit tests, bug reports, comments or
patches licensed under both licenses are really welcomed.

I hope many projects could adopt this code and together help solve the unicode
subject for PHP.
