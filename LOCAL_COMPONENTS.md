# ShopXO 本地组件清单

## 已下载或已安装

- ShopXO 单商户版源码：`D:\storeA\shopxo`
  - GitHub：<https://github.com/gongfuxiang/shopxo>
  - 当前提交：`4b1a76b0cc784472d0eca26e0576b89009f8c1e8`
- Nginx for Windows 1.30.4：`D:\storeA\packages\nginx-1.30.4`
- Nginx 原始压缩包：`D:\storeA\packages\nginx-1.30.4.zip`
- MySQL Community Server 8.0.46：已安装在系统中
- Redis 5.0.14.1：已安装在系统中
- PHP 8.2.31：已安装，已启用 `pdo_mysql` 和 `redis` 扩展

## 需要购买账号授权，尚未下载

- ShopXO 官方 IM 客服插件：<https://store.shopxo.net/goods-179.html?lang=zh>
  - 商城标价以购买页面为准；插件需要 Linux、PHP Swoole 和 CLI 常驻进程。
- 对象存储插件：<https://store.shopxo.net/goods-42.html?lang=zh>
  - 支持阿里云 OSS、腾讯云存储和七牛；需要购买后从 ShopXO 后台在线安装。

这些商业插件不在 ShopXO GitHub 仓库中，不能在没有已购账号和授权包的情况下直接下载。

## 当前限制

- 本机没有 Docker。
- 本机 PHP 未安装 Swoole，因此 Windows 本地环境不能运行官方 IM 客服服务端。
- 官方 IM 生产部署应使用 Linux，并配置 Swoole、进程守护和 Nginx WebSocket 反向代理。
