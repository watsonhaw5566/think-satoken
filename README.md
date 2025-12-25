# think-satoken

## 项目介绍

think-satoken 是一个基于 PHP 实现的 SaToken 权限认证框架，专为 ThinkPHP(6|8) 框架设计。实现了 Java SaToken
的核心功能，提供简洁易用的权限认证解决方案。

## 功能特性

- 🔐 **用户认证**：提供完整的登录、登出功能
- 🎯 **Token 管理**：支持 Token 的创建、验证
- 👥 **并发登录控制**：可配置是否允许同一账号多地登录
- 🚫 **权限拦截**：提供中间件实现请求的权限拦截
- 📝 **灵活配置**：多种配置选项适应不同的业务场景
- ⚡ **高性能**：基于缓存实现，性能优越
- ♻️ **滑动续期**：开启后访问会自动续期（`auto_renew`）
- ⏱️ **有效期查询**：提供过期时间与剩余有效秒数查询
- 🔍 **Token 格式验证**：内置严格 `UUID v4` 格式验证，提高安全性

## 安装

使用 Composer 安装 think-satoken：

```bash
composer require watsonhaw/think-satoken
```

## 配置

think-satoken 提供了丰富的配置选项，配置文件位于 `src/config/satoken.php`：

```php
return [
    // 自定义 Token header 名称
    'token_name' => '',
    // Token 有效期，单位秒(默认1天)
    'timeout' => 86400,
    // 是否允许同一账号多地登录
    'is_concurrent' => true,
    // 同一账号最大登录数量
    'max_login_count' => 10,
    // 是否启用滑动续期
    'auto_renew' => true,
    // 缓存存储类型，可选 'file' 或 'redis' (默认file)
    'store_type' => 'file',
];
```

## 使用示例

### 1. 登录认证

```php
use satoken\SaToken;

// 用户登录，返回生成的token
$token = SaToken::login(1001); // 1001 为用户ID

// 检查是否已登录
if (SaToken::isLogin($token)) {
    echo '用户已登录';
} else {
    echo '用户未登录';
}

// 获取当前登录用户ID
$loginId = SaToken::getCurrentLoginId($token);

// 用户登出
SaToken::logout($token);
```

### 2. 使用中间件

在 ThinkPHP 中配置中间件：

```php
// 在 app/middleware.php 中注册中间件
return [
    // 全局中间件
    // 'satoken\\middleware\\SatokenAuth',
    
    // 或者在路由中使用
    'router' => [
        'auth' => 'satoken\\middleware\\SatokenAuth',
    ],
];
```

然后在路由中使用：

```php
// 为需要认证的路由添加中间件
Route::get('api/user/profile', 'UserController@profile')->middleware('auth');
```

### 3. 令牌传递

推荐通过请求头传递令牌：

- `Authorization: Bearer <token>`（推荐）
- 或自定义头：`{token_name}: <token>`

示例：

```bash
# 推荐：使用 Authorization: Bearer 传递令牌
curl -H "Authorization: Bearer $TOKEN" https://api.example.com/user/profile

# 备选：使用自定义头 satoken 传递令牌
curl -H "{token_name}: $TOKEN" https://api.example.com/user/profile
```

注意：不建议通过查询参数或请求体传递令牌，以避免在日志、Referer 等渠道泄露。SaToken 会优先从 `Authorization: Bearer`
中提取令牌，其次从自定义头 `{token_name}` 读取。

## 异常处理

think-satoken 定义了以下异常类：

- `NotLoginException`: 未登录异常
- `TokenInvalidException`: 无效的token异常
- `SatokenException`: 基础异常类

你可以在代码中捕获并处理这些异常：

```php
use satoken\SaToken;
use satoken\exception\NotLoginException;
use satoken\exception\TokenInvalidException;

try {
    // 检查登录状态，如果未登录会抛出异常
    SaToken::checkLogin($token);
    // 继续执行需要登录的操作
} catch (NotLoginException $e) {
    echo '用户未登录: ' . $e->getMessage();
} catch (TokenInvalidException $e) {
    echo '无效的token: ' . $e->getMessage();
}
```

## 核心 API

### SaToken 类

#### `createToken(): string`

生成一个新的 Token 字符串（使用 `ramsey/uuid` 生成的 `UUID v4`）。

#### `login(int $loginId, array $extra = []): string`

用户登录，返回生成的 Token。

- `$loginId`: 用户登录 ID
- `$extra`: 额外自定义内容（数组），会与令牌一起存储，可通过 `getExtra`/`getTokenInfo` 读取

#### `logout(?string $token = null): bool`

用户登出，移除 Token 信息。

- `$token`: 用户 Token，为空时从请求中获取
- 返回值：是否登出成功

#### `isLogin(?string $token = null): bool`

检查用户是否已登录。

- `$token`: 用户 Token，为空时从请求中获取
- 来源优先级：`Authorization: Bearer` > 自定义头 `satoken`
- 返回值：是否已登录

#### `getCurrentLoginId(?string $token = null): int`

获取当前登录用户的 ID。

- `$token`: 用户 Token，为空时从请求中获取（同 `isLogin` 来源策略）
- 返回值：登录用户 ID
- 未登录时抛出 `NotLoginException` 异常
- Token 格式无效时抛出 `TokenInvalidException` 异常，消息为"无效的token格式"
- Token 内容无效时抛出 `TokenInvalidException` 异常，消息为"无效的token"

#### `checkLogin(?string $token = null): void`

检查用户是否已登录，如果未登录则抛出异常。

- `$token`: 用户 Token，为空时从请求中获取
- 调用 `getCurrentLoginId` 方法，会抛出相同的异常

#### `kickout(?string $token = null): bool`

强制踢出用户，使其登录失效。

- `$token`: 用户 Token，为空时从请求中获取
- 返回值：是否踢出成功

#### `getTokenExpireTime(?string $token = null): int`

获取指定 token 的过期时间戳（秒），为 0 表示不可用或未找到。

- `$token`: 用户 Token，为空时从请求中获取

#### `getTokenRemainingTime(?string $token = null): int`

获取指定 token 的剩余有效秒数，为 0 表示已过期或未找到。

- `$token`: 用户 Token，为空时从请求中获取

#### `getTokenInfo(?string $token = null): array`

获取指定 token 的完整信息（包含 `loginId`、`create_time`、`expire_time`、`extra`）。

- `$token`: 用户 Token，为空时从请求中获取

#### `getExtra(?string $token = null): array`

获取指定 token 的自定义 `extra` 内容（数组）。

- `$token`: 用户 Token，为空时从请求中获取

#### `setExtra(?string $token = null, array $extra = []): bool`

更新指定 token 的 `extra` 内容（数组）。若 token 已过期或无效，返回 `false`；更新后保持原剩余有效期不变。

- `$token`: 用户 Token，为空时从请求中获取
- `$extra`: 新的自定义内容（数组）

## 注意事项

1. 确保已正确配置 ThinkPHP 的缓存系统，因为 think-satoken 依赖缓存存储 Token 信息。

2. 根据业务需求调整 Token 有效期 (`timeout`) 并选择是否开启滑动续期 (`auto_renew`) 以平衡安全性和体验。

3. 系统会自动验证 Token 的格式是否为严格 `UUID v4`，不符合格式的 Token 会被立即拒绝。

4. 如果你的应用有特殊的认证需求，可以通过修改配置文件或扩展核心类来实现。

## 开发和测试

项目包含完整的单元测试，可以通过以下命令运行：

```bash
vendor/bin/phpunit
```

## License

本项目采用 MIT 开源许可证。

---

## 关于

think-satoken 是基于 Java SaToken 实现的 PHP 版本，专为 ThinkPHP 框架定制，提供简单、高效的权限认证解决方案。如果你有任何问题或建议，欢迎提交
Issue 或 Pull Request。