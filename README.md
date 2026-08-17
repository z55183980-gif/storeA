# 轻量单商户商城

面向小规模商品目录的单一商户商城。当前可配置少量商品，但商品数量不是固定上限；用户端和管理端使用 Vue 3 + Vite，后端使用 PHP 8.2 REST API，数据存储为 SQLite；Nginx + PHP-CGI 提供本地服务。Redis可用于后续在线状态和限流增强，核心业务不依赖MySQL。

## 目录

- `app/web`：Vue用户端源码
- `app/admin`：Vue管理端源码
- `app/api`：PHP API
- `database/store.sqlite`：SQLite数据库
- `database/schema.sql`、`seed.sql`：结构及种子数据
- `public`：前端构建产物和API入口
- `scripts`：初始化、构建、启动、停止和测试脚本

## 初始化与运行

```powershell
cd E:\storeA
.\scripts\setup.ps1
.\scripts\build.ps1
.\scripts\start.ps1
.\scripts\test.ps1
```

访问地址：

- 用户端：http://127.0.0.1:5173/（运行 `scripts\dev.ps1`）
- 管理端：http://127.0.0.1:5174/（运行 `scripts\dev.ps1`）
- 后端健康检查：http://127.0.0.1:8080/api/?route=health

停止服务：

```powershell
.\scripts\stop.ps1
```

## 热更新开发模式

运行 `scripts\dev.ps1` 后：

- 用户端：http://127.0.0.1:5173/
- 管理端：http://127.0.0.1:5174/
- 两个 Vite 开发服务器的 `/api` 请求代理到 PHP/Nginx 后端 `http://127.0.0.1:8080`。
- 修改 `app\web\src` 或 `app\admin\src` 后会自动热更新；修改 PHP 或 SQLite 后端代码时由 PHP-CGI按请求重新加载。

## 初始管理员

- 用户名：`admin`
- 本地初始密码：`ChangeMe123!`

初始化前可以通过 `STORE_ADMIN_PASSWORD` 环境变量设置新密码。生产部署前必须更换初始密码，并通过 `STORE_JWT_SECRET` 提供足够长的随机签名密钥。

## 业务规则

- 商品数量由管理员动态维护，不存在固定10个的系统限制；库存和上下架状态由后端校验。
- 登录用户只能读取自己的订单；游客下单返回随机访客令牌。
- 订单状态仅允许：待付款→已付款/取消，已付款→交付中/退款，交付中→完成/退款。
- 游客无需登录即可建立客服会话；64位随机令牌用于读取和发送该会话消息。
- 管理员通过签名且有过期时间的令牌访问商品、订单和客服接口。

## 数据备份

停止服务后复制 `database/store.sqlite` 即可完成完整业务数据备份。恢复时覆盖同名文件并重新启动。

## 故障排查

- 502：确认PHP-CGI监听 `127.0.0.1:9000`。
- 端口占用：运行 `scripts\stop.ps1` 后再次启动。
- SQLite不可用：检查PHP模块中是否包含 `pdo_sqlite` 和 `sqlite3`。
- 重置演示数据：再次运行 `scripts\setup.ps1`，该操作会删除并重建当前SQLite数据库。
