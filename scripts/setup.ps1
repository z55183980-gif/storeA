$ErrorActionPreference='Stop'
$php='D:\php-8.2.31-nts-Win32-vs16-x64\php.exe'
if(-not (Test-Path $php)){throw '找不到 PHP 8.2'}
& $php 'E:\storeA\scripts\setup.php'
