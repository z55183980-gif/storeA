$ports=8080,9000
foreach($port in $ports){Get-NetTCPConnection -LocalPort $port -State Listen -ErrorAction SilentlyContinue | ForEach-Object {Stop-Process -Id $_.OwningProcess -Force -ErrorAction SilentlyContinue}}
Write-Host '闪购商城本地服务已停止。'
