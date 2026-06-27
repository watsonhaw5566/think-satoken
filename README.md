# think-satoken

## 项目介绍

think-satoken 是一个基于 PHP 实现的 SaToken 权限认证框架，专为 ThinkPHP(6|8) 框架设计。实现了 Java SaToken
的核心功能，提供简洁易用的权限认证解决方案。

采用 **Facade（门面）** + **Interface（接口）** + **Container（容器）** 三层架构：

- **Facade**：`satoken\facade\SaToken` 提供静态风格调用，契合 ThinkPHP 使用习惯（与框架内部 `Cache::get()`、`Config::get()` 等门面完全一致）
- **Interface**：`satoken\SatokenInterface` 定义方法契约，便于替换实现或在测试中 Mock
- **Container**：通过 `satoken\Service` 在 `composer.json` 的 `extra.think.services` 中声明，ThinkPHP 应用初始化时自动完成接口到实现的绑定，Facade 无需手动注册即可使用

## 功能特性

- 🔐 **用户认证**：提供完整的登录、登出、踢出功能
- 🎯 **Token 管理**：支持 Token 的创建、格式验证、信息读取
- 👥 **并发登录控制**：可配置是否允许同一账号多地登录，支持 `max_login_count` 限制
- 🚫 **权限拦截**：提供 `checkLogin()` 与 `SatokenAuth` 中间件实现请求的权限拦截
- 📝 **灵活配置**：多种配置选项适应不同的业务场景
- ⚡ **高性能**：基于缓存实现，性能优越
- ♻️ **智能滑动续期**：开启后仅在剩余时间低于阈值时才刷新 TTL（`renew_threshold`），避免每次请求写缓存
- ⏱️ **有效期查询**：提供过期时间戳与剩余有效秒数查询
- 🔍 **Token 格式验证**：内置严格 `UUID v4` 格式验证，提高安全性
- 📦 **自定义附加信息**：登录时可附加自定义 `extra` 数据，并在会话中读取或更新
- 🔒 **Redis 原子并发保护**：自动检测缓存驱动，当使用 Redis 时启用分布式锁（SET NX EX），保障高并发下 token 列表读写的原子性

## 工作原理

think-satoken 通过在 ThinkPHP 缓存系统之上构建 token → loginId 的双向映射关系实现会话管理。当缓存驱动为 Redis 时，会在以下关键路径上使用分布式锁保障数据一致性：

| 操作 | 锁标识 | 说明 |
|------|--------|------|
| 登录 | `satoken:lock:login:{loginId}` | 保障同一用户并发登录时 `max_login_count` 限制正确生效 |
| 登出/踢出 | `satoken:lock:login:{loginId}` | 保障 token 列表移除操作不会因并发而丢失数据 |
| 滑动续期 | `satoken:lock:login:{loginId}` | 保障 loginIdKey 重建与 TTL 刷新的原子性 |
| setExtra | `satoken:lock:token:{token}` | 防止多个请求同时修改同一 token 的 extra 字段相互覆盖 |

锁使用 Redis 原生的 `SET key value NX EX ttl` 命令实现，这是 Redis 官方推荐的分布式锁模式：

- **NX（Not Exists）**：仅当 key 不存在时才设置，保证只有一个请求能获得锁
- **EX（Expire）**：同时设置过期时间，即使释放锁代码未执行也能自动解锁
- **循环等待**：在最大等待时间内轮询获取锁（默认 100–500 ms）
- **按用户粒度**：不同用户的操作完全并行，互不阻塞
- **finally 释放**：无论正常返回还是抛异常，锁都会释放，避免死锁

非 Redis 驱动（如 File、Memcache）下不启用分布式锁，因为单机缓存本身天然有序。

## 安装

使用 Composer 安装 think-satoken：

```bash
composer require watsonhaw/think-satoken
```

## 配置

think-satoken 提供了丰富的配置选项，配置文件位于 `src/config/satoken.php`：

```php
return [
    // 自定义 Token header 名称（为空时仅从 Authorization: Bearer 中读取）
    'token_name' => '',
    // Token 有效期，单位秒(默认1天)
    'timeout' => 86400,
    // 是否允许同一账号多地登录（false 等价于 max_login_count=1）
    'is_concurrent' => true,
    // 同一账号最大登录数量（超出后最早的 token 被踢出）
    'max_login_count' => 10,
    // 是否启用滑动续期（访问自动续期）
    'auto_renew' => true,
    // 滑动续期阈值：剩余时间低于此比例才触发续期 (0~1，默认 30%)
    // 设为 1 表示每次访问都续期
    'renew_threshold' => 0.3,
];
```

**并发登录说明**：

- 内部统一使用数组存储同一用户的多个 token，无需区分并发与非并发模式
- `is_concurrent=false` 等价于 `max_login_count=1`：后一次登录会替换前一次
- 超过 `max_login_count` 时，最早的 token 被踢出（自动过期删除）
- 登录时会自动清理列表中已过期的 token，不占用配额

**并发安全（Redis 模式）**：

- think-satoken 会**自动检测**当前缓存驱动是否为 Redis（`think\cache\driver\Redis`）
- 当检测到 Redis 驱动时，对所有涉及 token 列表读-改-写的操作启用**分布式锁**
- 非 Redis 驱动（如 File、Memcache）保持原有行为（适用于单机低并发场景）

可通过公开方法检测当前驱动类型：

```php
use satoken\facade\SaToken;

// 当前是否使用 Redis 驱动
$isRedis = SaToken::isRedisDriver();

// 重置内部驱动缓存（用于驱动动态切换场景）
SaToken::resetDriverDetection();
```

## 使用示例

### 1. 登录认证

```php
use satoken\facade\SaToken;

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

// 按 token 踢出单个会话（仅使指定 token 失效，不影响同一用户的其他登录）
SaToken::kickoutByToken($token);

// 按 loginId 踢出该用户的所有登录会话（一次调用，所有设备同时下线）
SaToken::kickout(1001); // 1001 为用户ID
```

### 2. 使用中间件

`SatokenAuth` 中间件会在请求处理前调用 `checkLogin()` 验证登录状态，
验证失败时直接返回 JSON 响应，无需额外捕获异常。

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

中间件的响应格式：

| 状态 | HTTP 状态码 | 返回 JSON 示例 |
|------|------------|----------------|
| 未提供 token | 401 | `{"code": <error_code>, "msg": "未提供token", "data": null}` |
| 无效 token | 401 | `{"code": <error_code>, "msg": "无效的token格式/无效的token", "data": null}` |
| 其他异常 | 400 或 500 | `{"code": <code>, "msg": <message>, "data": null}` |
| 通过校验 | — | 放行到下一层处理 |

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

注意：不建议通过查询参数或请求体传递令牌，以避免在日志、Referer 等渠道泄露。SaToken 会优先从自定义头 `{token_name}`
中提取令牌，其次从 `Authorization: Bearer` 读取。

## 异常处理

think-satoken 定义了以下异常类：

- `NotLoginException`: 未登录异常
- `TokenInvalidException`: 无效的token异常
- `SatokenException`: 基础异常类

你可以在代码中捕获并处理这些异常：

```php
use satoken\facade\SaToken;
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

#### `validateTokenFormat(string $token): bool`

验证 token 是否为严格的 `UUID v4` 格式（长度 36，版本号=4，变体位=8/9/a/b）。

```php
use satoken\facade\SaToken;

var_dump(SaToken::validateTokenFormat('not-a-uuid')); // false
```

#### `login(int $loginId, array $extra = []): string`

用户登录，返回生成的 Token。

- `$loginId`: 用户登录 ID
- `$extra`: 额外自定义内容（数组），会与令牌一起存储，可通过 `getExtra`/`getTokenInfo` 读取
- 超过 `max_login_count` 时会踢出最早的 token

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
- 未提供 token 时抛出 `NotLoginException`（消息："未提供token"）
- Token 格式无效时抛出 `TokenInvalidException`（消息："无效的token格式"）
- Token 内容无效（缓存中不存在）时抛出 `TokenInvalidException`（消息："无效的token"）
- Token 信息缺少 loginId 时抛出 `TokenInvalidException`（消息："token信息不完整"）
- 验证通过后会触发滑动续期逻辑（与 `isLogin` 保持一致）

#### `checkLogin(?string $token = null): void`

检查用户是否已登录，如果未登录或 token 无效则抛出对应异常。

- `$token`: 用户 Token；为 null 或空字符串时自动从请求中获取
- 与 `isLogin()` 判定结果保持一致：`isLogin()` 返回 true 的 token，`checkLogin()` 通过；反之则抛异常
- 与 `getCurrentLoginId()` 抛出的异常完全一致（见上表）
- 验证通过后会触发滑动续期（剩余时间低于 `renew_threshold` 时刷新 TTL）

> 典型用途：在权限拦截器或中间件中调用，无需关心 loginId，仅作为登录状态校验

#### `kickout(int $id): bool`

强制踢出指定用户的 **所有** 登录会话（一次调用，该用户的所有 token 同时失效）。

- `$id`: 用户登录 ID
- 返回值：是否踢出成功（至少有一个 token 被移除时返回 `true`；用户不存在或未登录时返回 `false`）
- 典型场景：管理员强制某用户下线、修改密码后清空旧会话

```php
use satoken\facade\SaToken;

// 踢出用户 1001 的所有设备
SaToken::kickout(1001);
```

#### `kickoutByToken(string $token): bool`

强制踢出 **单个** token 对应的登录会话，不影响同一用户的其他 token。

- `$token`: 用户 Token
- 返回值：是否踢出成功（token 无效、不存在或已过期时返回 `false`）
- 与 `logout()` 的区别：`logout` 是用户主动操作；`kickoutByToken` 是管理员/强制踢出语义

```php
use satoken\facade\SaToken;

// 仅踢出某个 token 对应的会话
SaToken::kickoutByToken($token);
```

#### `getTokenExpireTime(?string $token = null): int`

获取指定 token 的过期时间戳（秒），为 0 表示不可用或未找到。

- `$token`: 用户 Token，为空时从请求中获取

#### `getTokenRemainingTime(?string $token = null): int`

获取指定 token 的剩余有效秒数，为 0 表示已过期或未找到。

- `$token`: 用户 Token，为空时从请求中获取

#### `getTokenInfo(?string $token = null): array`

获取指定 token 的完整信息（包含 `loginId`、`create_time`、`expire_time`、`extra`）。

- `$token`: 用户 Token，为空时从请求中获取
- 失败时抛出与 `getCurrentLoginId` 相同的异常
- 验证通过后会触发滑动续期

#### `getExtra(?string $token = null): array`

获取指定 token 的自定义 `extra` 内容（数组）。如果 token 未设置 `extra`，返回空数组。

- `$token`: 用户 Token，为空时从请求中获取

#### `setExtra(?string $token = null, array $extra = []): bool`

更新指定 token 的 `extra` 内容（数组）。

- `$token`: 用户 Token，为空时从请求中获取
- `$extra`: 新的自定义内容（数组）
- 若 token 已过期、格式无效或不存在，返回 `false`
- 更新成功返回 `true`，并**保持原剩余有效期不变**（不会因更新 `extra` 而刷新 TTL）
- Redis 模式下按 token 粒度加锁，防止并发更新相互覆盖

#### `isRedisDriver(): bool`

检测当前缓存驱动是否为 Redis。

- 返回 `true`：当前驱动为 `think\cache\driver\Redis` 或类名包含 redis
- 返回 `false`：其他缓存驱动（File、Memcache 等）或检测失败

#### `resetDriverDetection(): void`

重置内部驱动检测缓存。

- 主要用于测试或运行时动态切换缓存驱动的场景
- 清空 `isRedisDriver()` 内部缓存的判定结果，使下一次调用会重新检测当前驱动

## 注意事项

1. 确保已正确配置 ThinkPHP 的缓存系统，因为 think-satoken 依赖缓存存储 Token 信息。

2. 根据业务需求调整 Token 有效期 (`timeout`) 并选择是否开启滑动续期 (`auto_renew`) 以平衡安全性和体验。

3. 系统会自动验证 Token 的格式是否为严格 `UUID v4`，不符合格式的 Token 会被立即拒绝。

4. 如果你的应用有特殊的认证需求，可以通过修改配置文件或扩展核心类来实现。

5. **高并发生产环境推荐使用 Redis 缓存**：只有当缓存驱动为 Redis 时，分布式锁才会生效，保障 `max_login_count` 限制和 token 列表维护在高并发下的正确性。如果使用 File 或其他本地缓存驱动，在多实例部署或极高并发场景下可能存在竞态条件。

6. **Redis 连接配置建议**：内部锁的超时时间（3–5 秒）已覆盖正常登录、登出操作的执行时长。在网络抖动或 Redis 本身有明显延迟的环境中，请确保 Redis 连接配置（`timeout`、`persistent` 等）合理。

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