# think-satoken

## 项目介绍

think-satoken 是一个轻量级权限认证扩展，专为 ThinkPHP(6|8) 框架设计。追求**简单、直观**，适合中小型项目使用。

## 设计理念

- **极简配置**：5 个核心配置项，开箱即用
- **单端登录**：默认同一账号同一时间只允许一个设备在线（新登录自动顶掉旧设备）
- **直观续期**：使用秒数而非百分比配置续期阈值，无需心算
- **无状态 Token**：使用纯 UUID v4 作为 token，无需签名，依赖缓存存储会话状态
- **缓存隔离**：通过 `store` 配置指定专用缓存通道，避免与业务缓存互相干扰

## 功能特性

- 🔐 **用户认证**：登录、登出、强制踢出
- 🎯 **Token 管理**：UUID v4 格式 token，内置严格格式验证
- 🚫 **权限拦截**：提供 `checkLogin()` 与 `SatokenAuth` 中间件
- ♻️ **智能滑动续期**：`renew_before`（秒数）控制续期时机，在过期前指定秒数内才刷新 TTL，避免每次请求写缓存
- ⏱️ **有效期查询**：过期时间戳与剩余有效秒数查询
- 📦 **自定义附加信息**：登录时可附加 `extra` 数据，支持运行时更新
- ⚡ **高性能**：基于 think-cache 实现，支持 File / Redis 等多种驱动

## 部署指南

### 单机部署

使用默认的 File 缓存即可，无需额外配置。

### 多机/多实例部署

多机部署（负载均衡环境）时，token 会话数据需要在多台服务器之间共享。请在配置中指定 `store` 为 `'redis'`：

```php
// config/satoken.php
return [
    'store' => 'redis',
    // ...
];
```

同时确保在 `config/cache.php` 中正确配置 Redis 缓存通道（参考 [ThinkPHP 缓存文档](https://doc.thinkphp.cn/v8_0/caches.html)）：

```php
// config/cache.php
return [
    'default' => 'file',
    'stores' => [
        // 业务默认缓存（file）
        'file' => [
            'type' => 'File',
            // ...
        ],
        // SaToken 专用 Redis 缓存
        'redis' => [
            'type' => 'redis',
            'host' => '127.0.0.1',
            'port' => 6379,
            'password' => '',
            'select' => 0,
            'timeout' => 0,
            'persistent' => false,
            'prefix' => 'satoken:',
        ],
    ],
];
```

**为什么需要 Redis？** File 缓存将数据存储在本地文件系统，不同服务器之间无法共享。用户在 A 机登录后，请求打到 B 机时 B 机的文件缓存中没有该 token，会导致误判为未登录。

## 安装

使用 Composer 安装：

```bash
composer require watsonhaw/think-satoken
```

## 配置

配置文件 `config/satoken.php`（发布后）：

```php
return [
    // 自定义 Token 请求头名称（为空则使用 Authorization: Bearer {token}）
    'token_name' => '',

    // 缓存通道名称（对应 cache.php 配置中的 stores 键名）
    // null 表示使用框架默认缓存
    // 多机部署请配置为 'redis' 并确保在 cache.php 中正确配置
    'store' => null,

    // Token 有效期（秒），默认 7 天
    'timeout' => 604800,

    // 是否启用滑动续期（用户活跃时自动延长有效期）
    'auto_renew' => true,

    // 在过期前多少秒续期（秒）：剩余有效期不足此值时才续期
    // 默认 3600 秒（1小时），避免每次请求都写缓存
    // 设为 0 表示每次访问都续期
    'renew_before' => 3600,
];
```

**配置说明**：

| 配置项 | 类型 | 默认值 | 说明 |
|--------|------|--------|------|
| `token_name` | string | `''` | 自定义请求头名称，为空时从 `Authorization: Bearer` 读取 |
| `store` | string\|null | `null` | 缓存通道名称，对应 `cache.php` 中的 `stores` 键；null 使用默认缓存 |
| `timeout` | int | `604800` | Token 有效期（秒），默认 7 天 |
| `auto_renew` | bool | `true` | 是否启用滑动续期 |
| `renew_before` | int | `3600` | 在过期前多少秒续期（秒），剩余不足此时才触发续期，设为 0 表示每次访问都续期 |

**滑动续期示例**：

- `timeout` = 604800（7天），`renew_before` = 3600（1小时）
- 用户登录后前 6 天访问时**不会**触发写操作（性能最优）
- 当剩余有效期不足 1 小时时访问，自动续期为新的 7 天
- 设 `renew_before` = 0 则每次访问都续期（最实时，但写缓存频繁）

**缓存隔离建议**：

即使是单机部署，也建议为 SaToken 配置独立的缓存通道（如单独的 Redis select 或独立的文件目录），通过 `prefix` 区分，便于管理和清理。

## 使用示例

### 1. 登录认证

```php
use satoken\facade\SaToken;

// 用户登录，返回生成的 token
$token = SaToken::login(1001); // 1001 为用户ID

// 登录时附加自定义数据
$token = SaToken::login(1001, ['role' => 'admin', 'tenant_id' => 42]);

// 检查是否已登录
if (SaToken::isLogin($token)) {
    echo '用户已登录';
}

// 获取当前登录用户ID
$loginId = SaToken::getCurrentLoginId($token);

// 获取自定义附加数据
$extra = SaToken::getExtra($token);

// 更新自定义附加数据（不影响有效期）
SaToken::setExtra($token, ['role' => 'editor']);

// 用户登出
SaToken::logout($token);

// 强制踢出用户（使其当前 token 失效）
SaToken::kickout(1001);

// 强制踢出指定 token
SaToken::kickoutByToken($token);
```

**单端登录行为**：同一账号再次调用 `login()` 时，旧 token 会自动失效（顶号）。

### 2. 使用中间件

`SatokenAuth` 中间件会在请求处理前调用 `checkLogin()` 验证登录状态，验证失败时直接返回 JSON 响应。

在 `app/middleware.php` 中注册：

```php
return [
    // 路由中间件
    'router' => [
        'auth' => 'satoken\middleware\SatokenAuth',
    ],
];
```

在路由中使用：

```php
Route::get('api/user/profile', 'UserController@profile')->middleware('auth');
```

中间件响应：

| 状态 | HTTP 状态码 | 返回 JSON |
|------|------------|-----------|
| 未提供 token | 401 | `{"code": ..., "msg": "未提供token", "data": null}` |
| 无效 token | 401 | `{"code": ..., "msg": "无效的token格式/无效的token", "data": null}` |
| 通过校验 | — | 放行 |

### 3. 令牌传递

推荐通过请求头传递令牌：

- `Authorization: Bearer <token>`（推荐）
- 或自定义头：`{token_name}: <token>`

```bash
curl -H "Authorization: Bearer $TOKEN" https://api.example.com/user/profile
```

## 异常处理

```php
use satoken\facade\SaToken;
use satoken\exception\NotLoginException;
use satoken\exception\TokenInvalidException;

try {
    SaToken::checkLogin();
    // 已登录，执行业务逻辑
} catch (NotLoginException $e) {
    // 未提供 token
} catch (TokenInvalidException $e) {
    // token 无效或已过期
}
```

## 核心 API

| 方法 | 说明 |
|------|------|
| `createToken(): string` | 生成 UUID v4 token |
| `validateTokenFormat(string $token): bool` | 验证 token 格式 |
| `login(int $loginId, array $extra = []): string` | 登录（旧 token 自动失效） |
| `logout(?string $token = null): bool` | 登出 |
| `isLogin(?string $token = null): bool` | 检查是否已登录 |
| `checkLogin(?string $token = null): void` | 校验登录状态，失败抛异常 |
| `getCurrentLoginId(?string $token = null): int` | 获取当前登录用户 ID |
| `getTokenInfo(?string $token = null): array` | 获取 token 完整信息 |
| `getExtra(?string $token = null): array` | 获取自定义附加数据 |
| `setExtra(?string $token = null, array $extra = []): bool` | 更新附加数据 |
| `getTokenExpireTime(?string $token = null): int` | 获取过期时间戳 |
| `getTokenRemainingTime(?string $token = null): int` | 获取剩余有效秒数 |
| `kickout(int $id): bool` | 强制踢出用户 |
| `kickoutByToken(string $token): bool` | 强制踢出指定 token |

## 缓存键说明

| 键 | 类型 | TTL | 说明 |
|----|------|-----|------|
| `satoken:token:{uuid}` | array | `timeout` | token 信息（loginId、create_time、expire_time、extra） |
| `satoken:loginId:{id}` | string | `timeout` | 用户 ID 到当前 token 的映射（单 token） |

> 如果配置了 `store` 指向自定义缓存通道（如 Redis），这些键将存储在对应通道中；如果该通道配置了 `prefix`，实际键名会自动加上该前缀。

## 开发和测试

```bash
# 运行单元测试
vendor/bin/phpunit

# 静态分析
vendor/bin/phpstan analyse
```

## License

MIT