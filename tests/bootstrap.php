<?php

namespace GravityPdf\Upload;

require dirname(__DIR__) . '/vendor/autoload.php';

/* Test doubles that are not themselves test cases, so nothing autoloads them */
require __DIR__ . '/Upload/Storage/ExposedFileSystem.php';
require __DIR__ . '/Upload/VouchedFileInfo.php';
