$ErrorActionPreference='Stop'
$base='http://127.0.0.1:8080'
$health=Invoke-RestMethod "$base/api/?route=health"
if($health.status -ne 'ok' -or $health.database -ne 'sqlite'){throw 'health check failed'}
$products=Invoke-RestMethod "$base/api/?route=products"
if($products.Count -gt 10){throw 'product limit failed'}
$html=(Invoke-WebRequest "$base/" -UseBasicParsing).StatusCode
$admin=(Invoke-WebRequest "$base/admin/" -UseBasicParsing).StatusCode
if($html -ne 200 -or $admin -ne 200){throw 'frontend check failed'}
$adminLogin=Invoke-RestMethod "$base/api/?route=admin/login" -Method Post -ContentType application/json -Body (@{username='admin';password='ChangeMe123!'}|ConvertTo-Json)
$headers=@{Authorization="Bearer $($adminLogin.token)"}
$adminProducts=Invoke-RestMethod "$base/api/?route=admin/products" -Headers $headers
if($adminProducts.Count -ne $products.Count){throw 'admin product query failed'}
$guest=Invoke-RestMethod "$base/api/?route=chat/session" -Method Post -ContentType application/json -Body (@{visitor_name='验收游客'}|ConvertTo-Json)
Invoke-RestMethod "$base/api/?route=chat/messages/$($guest.token)" -Method Post -ContentType application/json -Body (@{content='验收消息'}|ConvertTo-Json)|Out-Null
$chat=Invoke-RestMethod "$base/api/?route=chat/messages/$($guest.token)"
if($chat.Count -lt 2){throw 'guest chat failed'}
try{Invoke-RestMethod "$base/api/?route=orders" -Method Get -ErrorAction Stop;throw 'unauthorized order access was allowed'}catch{if($_.Exception.Response.StatusCode.value__ -ne 401){throw}}
Write-Host "PASS health/products/frontend/admin-auth/guest-chat/security ($($products.Count) products)"
