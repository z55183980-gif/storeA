$ErrorActionPreference='Stop'
$root='E:\storeA'; $php='D:\php-8.2.31-nts-Win32-vs16-x64\php-cgi.exe'; $redis='D:\Redis-8.0.5\redis-server.exe'; $nginx="$root\packages\nginx-1.30.4\nginx.exe"
if(-not (Test-Path "$root\database\store.sqlite")){& "$root\scripts\setup.ps1"}
if(-not (Get-NetTCPConnection -LocalPort 9000 -State Listen -ErrorAction SilentlyContinue)){Start-Process -FilePath $php -ArgumentList '-b','127.0.0.1:9000','-c','D:\php-8.2.31-nts-Win32-vs16-x64\php.ini' -WorkingDirectory $root -WindowStyle Hidden}
if((Test-Path $redis) -and -not (Get-NetTCPConnection -LocalPort 6379 -State Listen -ErrorAction SilentlyContinue)){Start-Process -FilePath $redis -WorkingDirectory 'D:\Redis-8.0.5' -WindowStyle Hidden}
& $nginx -t -p "$root\packages\nginx-1.30.4" -c "$root\local-nginx.conf"
if(-not (Get-NetTCPConnection -LocalPort 8080 -State Listen -ErrorAction SilentlyContinue)){Start-Process -FilePath $nginx -ArgumentList '-p',"$root\packages\nginx-1.30.4",'-c',"$root\local-nginx.conf" -WorkingDirectory "$root\packages\nginx-1.30.4" -WindowStyle Hidden}
Start-Sleep -Seconds 2
Write-Host '后端 API: http://127.0.0.1:8080/api/?route=health'
Write-Host '用户端请运行 scripts\dev.ps1 后访问: http://127.0.0.1:5173/'
Write-Host '管理端请运行 scripts\dev.ps1 后访问: http://127.0.0.1:5174/'
