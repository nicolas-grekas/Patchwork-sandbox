Patchwork-sandox?
=================

This repository holds a small "Hello World" application built on top of Patchwork and packaged for easy installation.

Requirements
------------

Patchwork runs on PHP 5.2.0 or later. It works best when the `dba` and `mbstring` extensions are enabled.

Git 1.6.5 or later is needed for downloading the code. See https://code.google.com/p/msysgit/ on Windows.

Installation
------------

On Linux or Windows:

 1. Open the command shell and go into your _www_ folder
 3. Download the code with:
    `git clone --recursive git://github.com/nicolas-grekas/Patchwork-sandbox.git`
 4. Open your browser to http://localhost/Patchwork-sandbox/
 5. Hello World!

If you want friendly URLs and your web server is Apache,
try to copy `htaccess` to `.htaccess` and tweak its content if needed.
