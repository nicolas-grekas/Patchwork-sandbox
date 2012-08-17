<?php // vi: set fenc=utf-8 ts=4 sw=4 et:

// Cache directory of your patchwork application
define('PATCHWORK_BOOTPATH', './cache');

// Include patchwork's bootstrapper.php
include './vendor/patchwork/bootstrapper.php';

// Let's go
Patchwork::start();
