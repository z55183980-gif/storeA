<?php
declare(strict_types=1);

$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, ['http://127.0.0.1:8080', 'http://localhost:8080', 'http://127.0.0.1:5174', 'http://localhost:5174', 'http://127.0.0.1:4173', 'http://localhost:4173'], true)) {
    header('Access-Control-Allow-Origin: '.$origin);
    header('Vary: Origin');
}
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Headers: Content-Type, Authorization');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') { http_response_code(204); exit; }

$config = require __DIR__.'/config.php';
try {
    $db = new PDO($config['dsn'], null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
    $db->exec('PRAGMA foreign_keys=ON');
    $db->exec('PRAGMA busy_timeout=5000');
    $db->exec('CREATE TABLE IF NOT EXISTS captcha_challenges (id TEXT PRIMARY KEY, answer_hash TEXT NOT NULL, expires_at INTEGER NOT NULL, created_at TEXT DEFAULT CURRENT_TIMESTAMP)');
    $db->exec("CREATE TABLE IF NOT EXISTS payment_methods (id INTEGER PRIMARY KEY AUTOINCREMENT, paytype TEXT UNIQUE NOT NULL, paytypetitle TEXT NOT NULL, ftitle TEXT NOT NULL DEFAULT '', minmoney NUMERIC NOT NULL DEFAULT 100 CHECK(minmoney>=0), maxmoney NUMERIC NOT NULL DEFAULT 50000 CHECK(maxmoney>=minmoney), isonline INTEGER NOT NULL DEFAULT 0 CHECK(isonline IN (0,1)), state INTEGER NOT NULL DEFAULT 1 CHECK(state IN (0,1)), configs TEXT NOT NULL DEFAULT '{}', remark TEXT NOT NULL DEFAULT '', listorder INTEGER NOT NULL DEFAULT 0, created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)");
    $columns=$db->query('PRAGMA table_info(products)')->fetchAll(PDO::FETCH_COLUMN,1);
    if (!in_array('original_price',$columns,true)) $db->exec('ALTER TABLE products ADD COLUMN original_price NUMERIC NULL');
    if (!in_array('sales',$columns,true)) $db->exec('ALTER TABLE products ADD COLUMN sales INTEGER NOT NULL DEFAULT 0');
    $chatColumns=$db->query('PRAGMA table_info(chat_sessions)')->fetchAll(PDO::FETCH_COLUMN,1);
    if (!in_array('admin_last_read_message_id',$chatColumns,true)) $db->exec('ALTER TABLE chat_sessions ADD COLUMN admin_last_read_message_id INTEGER NOT NULL DEFAULT 0');
} catch (Throwable) {
    respond(['error' => 'database_unavailable'], 500);
}

function respond(mixed $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}
function input(): array {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
}
function b64e(string $value): string { return rtrim(strtr(base64_encode($value), '+/', '-_'), '='); }
function b64d(string $value): string { return (string)base64_decode(strtr($value, '-_', '+/')); }
function issueToken(string $role, int $id, string $secret): string {
    $payload = b64e(json_encode(['role'=>$role, 'id'=>$id, 'exp'=>time()+86400], JSON_UNESCAPED_SLASHES));
    return $payload.'.'.b64e(hash_hmac('sha256', $payload, $secret, true));
}
function issueCaptchaToken(string $id, int $expires, string $secret): string {
    $payload = b64e(json_encode(['id'=>$id, 'exp'=>$expires], JSON_UNESCAPED_SLASHES));
    return $payload.'.'.b64e(hash_hmac('sha256', $payload, $secret, true));
}
function captchaTokenPayload(string $token, string $secret): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 2 || !hash_equals(b64e(hash_hmac('sha256', $parts[0], $secret, true)), $parts[1])) return null;
    $payload = json_decode(b64d($parts[0]), true);
    return is_array($payload) && is_string($payload['id'] ?? null) && ($payload['exp'] ?? 0) >= time() ? $payload : null;
}
function captchaSvg(string $value): string {
    $escaped = htmlspecialchars($value, ENT_QUOTES | ENT_XML1, 'UTF-8');
    $bars = '';
    for ($i = 0; $i < 14; $i++) {
        $x = 8 + (($i * 29) % 132); $y = 8 + (($i * 17) % 34);
        $bars .= '<path d="M'.$x.' '.$y.'l'.(($i % 2) ? 10 : -8).' '.(($i % 3) + 4).'" stroke="#'.(($i % 2) ? 'b7c6e6' : 'd6b9c8').'" stroke-width="1"/>';
    }
    return 'data:image/svg+xml,'.rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="150" height="50" viewBox="0 0 150 50"><rect width="150" height="50" rx="8" fill="#f4f7ff"/>'.$bars.'<text x="75" y="34" text-anchor="middle" font-family="Arial,sans-serif" font-size="24" font-weight="700" letter-spacing="5" fill="#315efb">'.$escaped.'</text></svg>');
}
function verifyCaptcha(PDO $db, array $data, string $secret): void {
    $token = trim((string)($data['captcha_token'] ?? ''));
    $code = strtoupper(trim((string)($data['captcha_code'] ?? '')));
    $payload = captchaTokenPayload($token, $secret);
    if (!$payload || $code === '' || mb_strlen($code) > 8) respond(['error'=>'验证码无效或已过期'], 422);
    $stmt = $db->prepare('SELECT answer_hash, expires_at FROM captcha_challenges WHERE id=?');
    $stmt->execute([$payload['id']]); $challenge = $stmt->fetch();
    if (!$challenge || (int)$challenge['expires_at'] < time() || !hash_equals($challenge['answer_hash'], hash('sha256', $code))) respond(['error'=>'验证码错误或已过期'], 422);
    $db->prepare('DELETE FROM captcha_challenges WHERE id=?')->execute([$payload['id']]);
}
function principal(string $secret): ?array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/^Bearer\s+(.+)$/i', $header, $match)) return null;
    $parts = explode('.', $match[1]);
    if (count($parts) !== 2 || !hash_equals(b64e(hash_hmac('sha256', $parts[0], $secret, true)), $parts[1])) return null;
    $payload = json_decode(b64d($parts[0]), true);
    return is_array($payload) && ($payload['exp'] ?? 0) >= time() ? $payload : null;
}
function requireRole(string $role, string $secret): array {
    $actor = principal($secret);
    if (!$actor || ($actor['role'] ?? '') !== $role) respond(['error'=>'unauthorized'], 401);
    return $actor;
}
function requireText(array $data, string $key, int $min = 1, int $max = 255): string {
    $value = trim((string)($data[$key] ?? ''));
    if (mb_strlen($value) < $min || mb_strlen($value) > $max) respond(['error'=>$key.'_invalid'], 422);
    return $value;
}
function paymentMethodPayload(array $data, ?array $current = null): array {
    $allowed = ['alipay','weixin','linepay','USDT','EpUSDT'];
    $paytype = trim((string)($data['paytype'] ?? ''));
    if (!in_array($paytype, $allowed, true)) respond(['error'=>'请选择系统预设的支付标识'], 422);
    if ($current && $paytype !== $current['paytype']) respond(['error'=>'支付标识创建后不可修改'], 409);
    $title = requireText($data, 'paytypetitle', 1, 80);
    $min = (float)($data['minmoney'] ?? 100); $max = (float)($data['maxmoney'] ?? 50000);
    if ($min < 0 || $max < $min || $max > 999999999) respond(['error'=>'充值金额范围无效'], 422);
    $configs = $data['configs_data'] ?? [];
    if (!is_array($configs)) $configs = [];
    $allowedConfigKeys = ['ewmurl','account','accountname','bankname','bankbranch','bankcode','rate','trc20','erc20'];
    $cleanConfigs = [];
    foreach ($allowedConfigKeys as $key) if (array_key_exists($key, $configs)) $cleanConfigs[$key] = mb_substr(trim((string)$configs[$key]), 0, 500);
    if ($paytype === 'EpUSDT' && !preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $cleanConfigs['trc20'] ?? '')) respond(['error'=>'请输入有效的 TRC20 收款地址'], 422);
    return [$paytype,$title,mb_substr(trim((string)($data['ftitle']??'')),0,80),$min,$max,$paytype==='EpUSDT'?1:((int)($data['isonline']??0)?1:0),(int)($data['state']??1)?1:0,json_encode($cleanConfigs,JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES),mb_substr(trim((string)($data['remark']??'')),0,1000),(int)($data['listorder']??0)];
}
function paymentMethodRow(array $row): array {
    $row['id']=(int)$row['id'];$row['minmoney']=(float)$row['minmoney'];$row['maxmoney']=(float)$row['maxmoney'];$row['isonline']=(int)$row['isonline'];$row['state']=(int)$row['state'];$row['listorder']=(int)$row['listorder'];
    $configs=json_decode((string)$row['configs'],true);$row['configs_data']=is_array($configs)?$configs:[];unset($row['configs']);return $row;
}

$method = $_SERVER['REQUEST_METHOD'];
$route = trim((string)($_GET['route'] ?? ''), '/');
$data = input();

try {
    if ($method === 'GET' && $route === 'health') respond(['status'=>'ok', 'database'=>'sqlite']);

    if ($method === 'GET' && $route === 'products') {
        respond($db->query("SELECT id,name,description,price,original_price,stock,sales,image_url,status,sort_order FROM products WHERE status='on_sale' ORDER BY sort_order,id")->fetchAll());
    }

    if ($method === 'GET' && $route === 'auth/captcha') {
        $characters = '23456789ABCDEFGHJKLMNPQRSTUVWXYZ'; $code = '';
        for ($i = 0; $i < 4; $i++) $code .= $characters[random_int(0, strlen($characters) - 1)];
        $id = bin2hex(random_bytes(16)); $expires = time() + 300;
        $db->prepare('DELETE FROM captcha_challenges WHERE expires_at < ?')->execute([time()]);
        $db->prepare('INSERT INTO captcha_challenges(id,answer_hash,expires_at) VALUES(?,?,?)')->execute([$id, hash('sha256', $code), $expires]);
        respond(['captcha_id'=>$id, 'captcha_token'=>issueCaptchaToken($id, $expires, $config['jwt_secret']), 'captcha_image'=>captchaSvg($code), 'expires_in'=>300]);
    }
    if ($method === 'POST' && $route === 'auth/register') {
        $username = requireText($data, 'username', 3, 64); $password = requireText($data, 'password', 6, 128);
        verifyCaptcha($db, $data, $config['jwt_secret']);
        $exists = $db->prepare('SELECT 1 FROM users WHERE username=?'); $exists->execute([$username]);
        if ($exists->fetchColumn()) respond(['error'=>'用户名已存在'], 409);
        $stmt = $db->prepare('INSERT INTO users(username,password_hash) VALUES(?,?)');
        $stmt->execute([$username, password_hash($password, PASSWORD_DEFAULT)]);
        respond(['token'=>issueToken('user',(int)$db->lastInsertId(),$config['jwt_secret']),'username'=>$username], 201);
    }
    if ($method === 'POST' && $route === 'auth/login') {
        $stmt=$db->prepare('SELECT * FROM users WHERE username=?'); $stmt->execute([(string)($data['username']??'')]); $user=$stmt->fetch();
        if (!$user || $user['status']!=='active' || !password_verify((string)($data['password']??''),$user['password_hash'])) respond(['error'=>'账号或密码错误'],401);
        respond(['token'=>issueToken('user',(int)$user['id'],$config['jwt_secret']),'username'=>$user['username']]);
    }
    if ($method === 'POST' && $route === 'admin/login') {
        $stmt=$db->prepare('SELECT * FROM admins WHERE username=?'); $stmt->execute([(string)($data['username']??'')]); $admin=$stmt->fetch();
        if (!$admin || !password_verify((string)($data['password']??''),$admin['password_hash'])) respond(['error'=>'账号或密码错误'],401);
        respond(['token'=>issueToken('admin',(int)$admin['id'],$config['jwt_secret']),'username'=>$admin['username']]);
    }

    if ($method === 'POST' && $route === 'orders') {
        $items=$data['items']??[]; if(!is_array($items)||count($items)<1) respond(['error'=>'订单不能为空'],422);
        $actor=principal($config['jwt_secret']); $userId=($actor['role']??'')==='user'?(int)$actor['id']:null; $guest=$userId?null:(string)($data['guest_token']??bin2hex(random_bytes(24)));
        $db->beginTransaction(); $resolved=[]; $total=0.0;
        foreach($items as $item){$stmt=$db->prepare("SELECT * FROM products WHERE id=? AND status='on_sale'");$stmt->execute([(int)($item['product_id']??0)]);$product=$stmt->fetch();$qty=max(1,min(99,(int)($item['quantity']??1)));if(!$product||$product['stock']<$qty)throw new RuntimeException('商品不存在或库存不足');$resolved[]=[$product,$qty];$total+=(float)$product['price']*$qty;}
        $orderNo='LS'.date('YmdHis').random_int(100,999);$stmt=$db->prepare('INSERT INTO orders(order_no,user_id,guest_token,total_amount,contact_name,contact_phone,remark) VALUES(?,?,?,?,?,?,?)');$stmt->execute([$orderNo,$userId,$guest,number_format($total,2,'.',''),mb_substr((string)($data['contact_name']??''),0,80),mb_substr((string)($data['contact_phone']??''),0,40),mb_substr((string)($data['remark']??''),0,500)]);$orderId=(int)$db->lastInsertId();
        foreach($resolved as [$product,$qty]){$db->prepare('INSERT INTO order_items(order_id,product_id,product_name,unit_price,quantity) VALUES(?,?,?,?,?)')->execute([$orderId,$product['id'],$product['name'],$product['price'],$qty]);$db->prepare('UPDATE products SET stock=stock-?,sales=sales+? WHERE id=? AND stock>=?')->execute([$qty,$qty,$product['id'],$qty]);}
        $db->commit(); respond(['order_no'=>$orderNo,'guest_token'=>$guest,'total_amount'=>number_format($total,2,'.',''),'status'=>'pending'],201);
    }
    if ($method === 'GET' && $route === 'orders') {
        $actor=requireRole('user',$config['jwt_secret']);$stmt=$db->prepare('SELECT * FROM orders WHERE user_id=? ORDER BY id DESC');$stmt->execute([(int)$actor['id']]);respond($stmt->fetchAll());
    }

    if ($method === 'POST' && $route === 'chat/session') {
        $actor=principal($config['jwt_secret']);$userId=($actor['role']??'')==='user'?(int)$actor['id']:null;$name=trim((string)($data['visitor_name']??'游客'));if($name==='')$name='游客';$token=bin2hex(random_bytes(32));
        $db->prepare('INSERT INTO chat_sessions(token,user_id,visitor_name) VALUES(?,?,?)')->execute([$token,$userId,mb_substr($name,0,64)]);$id=(int)$db->lastInsertId();$db->prepare('INSERT INTO chat_messages(session_id,sender,content) VALUES(?,?,?)')->execute([$id,'system','您好，欢迎咨询！']);respond(['token'=>$token,'session_id'=>$id],201);
    }
    if (preg_match('#^chat/messages/([a-f0-9]{64})$#',$route,$match)) {
        $stmt=$db->prepare('SELECT id,status FROM chat_sessions WHERE token=?');$stmt->execute([$match[1]]);$session=$stmt->fetch();if(!$session)respond(['error'=>'会话不存在'],404);
        if($method==='GET'){$stmt=$db->prepare('SELECT id,sender,content,created_at FROM chat_messages WHERE session_id=? ORDER BY id');$stmt->execute([$session['id']]);respond($stmt->fetchAll());}
        if($method==='POST'){if($session['status']!=='open')respond(['error'=>'会话已关闭'],409);$content=requireText($data,'content',1,2000);$db->prepare('INSERT INTO chat_messages(session_id,sender,content) VALUES(?,?,?)')->execute([$session['id'],'visitor',$content]);$db->prepare('UPDATE chat_sessions SET last_message_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$session['id']]);respond(['ok'=>true],201);}
    }

    if (str_starts_with($route,'admin/')) requireRole('admin',$config['jwt_secret']);
    if ($method==='GET' && $route==='admin/products') respond($db->query('SELECT * FROM products ORDER BY sort_order,id')->fetchAll());
    if ($method==='GET' && $route==='admin/stats') {
        respond(['products'=>(int)$db->query("SELECT COUNT(*) FROM products WHERE status='on_sale'")->fetchColumn(),'orders'=>(int)$db->query('SELECT COUNT(*) FROM orders')->fetchColumn(),'revenue'=>(float)$db->query("SELECT COALESCE(SUM(total_amount),0) FROM orders WHERE status NOT IN ('cancelled','refunded')")->fetchColumn(),'users'=>(int)$db->query('SELECT COUNT(*) FROM users')->fetchColumn(),'pending'=>(int)$db->query("SELECT COUNT(*) FROM orders WHERE status='pending'")->fetchColumn()]);
    }
    if ($method==='GET' && $route==='admin/users') respond($db->query('SELECT id,username,status,created_at FROM users ORDER BY id DESC')->fetchAll());
    if ($method==='GET' && $route==='admin/categories') respond([['name'=>'全部商品','product_count'=>(int)$db->query('SELECT COUNT(*) FROM products')->fetchColumn()],['name'=>'在售商品','product_count'=>(int)$db->query("SELECT COUNT(*) FROM products WHERE status='on_sale'")->fetchColumn()],['name'=>'下架商品','product_count'=>(int)$db->query("SELECT COUNT(*) FROM products WHERE status='off_sale'")->fetchColumn()]]);
    if ($method==='GET' && $route==='admin/payment-methods') respond(array_map('paymentMethodRow',$db->query('SELECT * FROM payment_methods ORDER BY listorder,id')->fetchAll()));
    if ($method==='POST' && $route==='admin/payment-methods/upload') {
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) respond(['error'=>'二维码上传失败'],422);
        if ((int)$_FILES['image']['size'] > 5 * 1024 * 1024) respond(['error'=>'图片不能超过5MB'],422);
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($_FILES['image']['tmp_name']);$extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
        if (!isset($extensions[$mime])) respond(['error'=>'仅支持 JPG、PNG、WEBP、GIF 图片'],422);
        $dir=dirname(__DIR__,2).'/public/payment-images';if(!is_dir($dir))mkdir($dir,0775,true);$name='payment-'.bin2hex(random_bytes(8)).'.'.$extensions[$mime];
        if(!move_uploaded_file($_FILES['image']['tmp_name'],$dir.'/'.$name))respond(['error'=>'图片保存失败'],500);respond(['url'=>'/payment-images/'.$name],201);
    }
    if ($method==='POST' && $route==='admin/payment-methods') {
        $values=paymentMethodPayload($data);$exists=$db->prepare('SELECT 1 FROM payment_methods WHERE paytype=?');$exists->execute([$values[0]]);if($exists->fetchColumn())respond(['error'=>'支付标识已存在'],409);$stmt=$db->prepare('INSERT INTO payment_methods(paytype,paytypetitle,ftitle,minmoney,maxmoney,isonline,state,configs,remark,listorder) VALUES(?,?,?,?,?,?,?,?,?,?)');$stmt->execute($values);respond(['id'=>(int)$db->lastInsertId()],201);
    }
    if ($method==='POST' && preg_match('#^admin/payment-methods/(\d+)/save$#',$route,$match)) {
        $find=$db->prepare('SELECT * FROM payment_methods WHERE id=?');$find->execute([(int)$match[1]]);$current=$find->fetch();if(!$current)respond(['error'=>'充值方式不存在'],404);
        $values=paymentMethodPayload($data,$current);$values[]=(int)$match[1];$stmt=$db->prepare('UPDATE payment_methods SET paytype=?,paytypetitle=?,ftitle=?,minmoney=?,maxmoney=?,isonline=?,state=?,configs=?,remark=?,listorder=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');$stmt->execute($values);respond(['ok'=>true]);
    }
    if ($method==='POST' && preg_match('#^admin/payment-methods/(\d+)/status$#',$route,$match)) { $stmt=$db->prepare('UPDATE payment_methods SET state=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');$stmt->execute([(int)($data['state']??0)?1:0,(int)$match[1]]);if(!$stmt->rowCount())respond(['error'=>'充值方式不存在'],404);respond(['ok'=>true]); }
    if ($method==='POST' && preg_match('#^admin/payment-methods/(\d+)/delete$#',$route,$match)) { $stmt=$db->prepare('DELETE FROM payment_methods WHERE id=?');$stmt->execute([(int)$match[1]]);if(!$stmt->rowCount())respond(['error'=>'充值方式不存在'],404);respond(['ok'=>true]); }
    if ($method==='POST' && $route==='admin/products/upload') {
        if (!isset($_FILES['image']) || $_FILES['image']['error'] !== UPLOAD_ERR_OK) respond(['error'=>'图片上传失败'],422);
        if ((int)$_FILES['image']['size'] > 5 * 1024 * 1024) respond(['error'=>'图片不能超过5MB'],422);
        $mime=(new finfo(FILEINFO_MIME_TYPE))->file($_FILES['image']['tmp_name']);
        $extensions=['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
        if (!isset($extensions[$mime])) respond(['error'=>'仅支持 JPG、PNG、WEBP、GIF 图片'],422);
        $dir=dirname(__DIR__,2).'/public/product-images'; if (!is_dir($dir)) mkdir($dir,0775,true);
        $name='product-'.bin2hex(random_bytes(8)).'.'.$extensions[$mime];
        if (!move_uploaded_file($_FILES['image']['tmp_name'],$dir.'/'.$name)) respond(['error'=>'图片保存失败'],500);
        respond(['url'=>'/product-images/'.$name],201);
    }
    if ($method==='POST' && $route==='admin/products') {
        $name=requireText($data,'name',1,120);$price=(float)($data['price']??-1);$original=($data['original_price']??'')===''?null:(float)$data['original_price'];$stock=(int)($data['stock']??0);$sales=(int)($data['sales']??0);if($price<0||($original!==null&&$original<0)||$stock<0||$sales<0)respond(['error'=>'price_stock_or_sales_invalid'],422);
        $stmt=$db->prepare('INSERT INTO products(name,description,price,original_price,stock,sales,image_url,status,sort_order) VALUES(?,?,?,?,?,?,?,?,?)');$stmt->execute([$name,mb_substr((string)($data['description']??''),0,5000),$price,$original,$stock,$sales,(string)($data['image_url']??''),($data['status']??'on_sale')==='off_sale'?'off_sale':'on_sale',(int)($data['sort_order']??0)]);respond(['id'=>(int)$db->lastInsertId()],201);
    }
    if (($method==='PUT' && preg_match('#^admin/products/(\d+)$#',$route,$match)) || ($method==='POST' && preg_match('#^admin/products/(\d+)/save$#',$route,$match))) {
        $original=($data['original_price']??'')===''?null:(float)$data['original_price'];if($original!==null&&$original<0)respond(['error'=>'original_price_invalid'],422);$stmt=$db->prepare('UPDATE products SET name=?,description=?,price=?,original_price=?,stock=?,sales=?,image_url=?,status=?,sort_order=?,updated_at=CURRENT_TIMESTAMP WHERE id=?');$stmt->execute([requireText($data,'name',1,120),mb_substr((string)($data['description']??''),0,5000),(float)($data['price']??0),$original,max(0,(int)($data['stock']??0)),max(0,(int)($data['sales']??0)),(string)($data['image_url']??''),($data['status']??'on_sale')==='off_sale'?'off_sale':'on_sale',(int)($data['sort_order']??0),(int)$match[1]]);respond(['ok'=>true]);
    }
    if (($method==='DELETE' && preg_match('#^admin/products/(\d+)$#',$route,$match)) || ($method==='POST' && preg_match('#^admin/products/(\d+)/delete$#',$route,$match))) { $stmt=$db->prepare('DELETE FROM products WHERE id=?'); $stmt->execute([(int)$match[1]]); respond(['ok'=>true]); }
    if ($method==='GET' && $route==='admin/orders') respond($db->query('SELECT * FROM orders ORDER BY id DESC')->fetchAll());
    if ($method==='GET' && preg_match('#^admin/orders/(\d+)$#',$route,$match)) {
        $stmt=$db->prepare('SELECT * FROM orders WHERE id=?');$stmt->execute([(int)$match[1]]);$order=$stmt->fetch();
        if(!$order)respond(['error'=>'订单不存在'],404);
        $stmt=$db->prepare('SELECT id,product_id,product_name,unit_price,quantity FROM order_items WHERE order_id=? ORDER BY id');$stmt->execute([(int)$match[1]]);$order['items']=$stmt->fetchAll();respond($order);
    }
    if (($method==='PUT' || $method==='POST') && preg_match('#^admin/orders/(\d+)/status$#',$route,$match)) {
        $allowed=['pending'=>['paid','cancelled'],'paid'=>['delivering','refunded'],'delivering'=>['completed','refunded'],'completed'=>[],'cancelled'=>[],'refunded'=>[]];$stmt=$db->prepare('SELECT status FROM orders WHERE id=?');$stmt->execute([(int)$match[1]]);$order=$stmt->fetch();$next=(string)($data['status']??'');if(!$order||!in_array($next,$allowed[$order['status']]??[],true))respond(['error'=>'非法订单状态转换'],409);$db->prepare('UPDATE orders SET status=? WHERE id=?')->execute([$next,(int)$match[1]]);respond(['ok'=>true]);
    }
    if ($method==='GET' && $route==='admin/chats') respond($db->query("SELECT s.*,COALESCE((SELECT m.content FROM chat_messages m WHERE m.session_id=s.id ORDER BY m.id DESC LIMIT 1),'') AS latest_message,COALESCE((SELECT m.sender FROM chat_messages m WHERE m.session_id=s.id ORDER BY m.id DESC LIMIT 1),'') AS latest_sender,(SELECT COUNT(*) FROM chat_messages m WHERE m.session_id=s.id AND m.sender='visitor' AND m.id>s.admin_last_read_message_id) AS unread_count FROM chat_sessions s ORDER BY s.last_message_at DESC,s.id DESC")->fetchAll());
    if (preg_match('#^admin/chats/(\d+)/messages$#',$route,$match)) {
        $stmt=$db->prepare('SELECT id,status FROM chat_sessions WHERE id=?');$stmt->execute([(int)$match[1]]);$session=$stmt->fetch();if(!$session)respond(['error'=>'会话不存在'],404);
        if($method==='GET'){$stmt=$db->prepare('SELECT id,sender,content,created_at FROM chat_messages WHERE session_id=? ORDER BY id');$stmt->execute([$session['id']]);respond($stmt->fetchAll());}
        if($method==='POST'){if($session['status']!=='open')respond(['error'=>'会话已关闭，请先恢复会话'],409);$content=requireText($data,'content',1,2000);$db->prepare('INSERT INTO chat_messages(session_id,sender,content) VALUES(?,?,?)')->execute([$session['id'],'agent',$content]);$db->prepare('UPDATE chat_sessions SET last_message_at=CURRENT_TIMESTAMP WHERE id=?')->execute([$session['id']]);respond(['ok'=>true],201);}
    }
    if (($method==='PUT' || $method==='POST') && preg_match('#^admin/chats/(\d+)/status$#',$route,$match)) { $status=($data['status']??'')==='closed'?'closed':'open'; $db->prepare('UPDATE chat_sessions SET status=? WHERE id=?')->execute([$status,(int)$match[1]]); respond(['ok'=>true]); }
    if (($method==='PUT' || $method==='POST') && preg_match('#^admin/chats/(\d+)/read$#',$route,$match)) { $stmt=$db->prepare('UPDATE chat_sessions SET admin_last_read_message_id=COALESCE((SELECT MAX(id) FROM chat_messages WHERE session_id=?),admin_last_read_message_id) WHERE id=?'); $stmt->execute([(int)$match[1],(int)$match[1]]); if(!$stmt->rowCount())respond(['error'=>'会话不存在'],404); respond(['ok'=>true]); }
    respond(['error'=>'not_found'],404);
} catch (PDOException $e) {
    if($db->inTransaction())$db->rollBack();$message=str_contains($e->getMessage(),'10个有效商品')?'最多只能有10个有效商品':'database_error';respond(['error'=>$message],409);
} catch (Throwable $e) {
    if($db->inTransaction())$db->rollBack();respond(['error'=>$e->getMessage()],500);
}
