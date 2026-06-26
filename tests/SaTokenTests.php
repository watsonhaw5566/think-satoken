<?php

namespace satoken\tests;

use satoken\exception\NotLoginException;
use satoken\exception\TokenInvalidException;
use satoken\SaToken;
use think\facade\Cache;

/**
 * SaToken 单元测试
 */
class SaTokenTests extends ThinkTestCase
{
    // 测试用的用户ID
    const TEST_USER_ID = 1001;

    const ANOTHER_USER_ID = 1002;

    /**
     * 测试 createToken 方法是否返回非空字符串
     */
    public function test_create_token_returns_non_empty_string()
    {
        // 调用 createToken 方法
        $token = SaToken::createToken();

        // 验证返回值是否为字符串且非空
        $this->assertIsString($token);
        $this->assertNotEmpty($token);
    }

    /**
     * 测试过期时间与剩余时间查询
     */
    public function test_token_expire_and_remaining_time_queries_work()
    {
        set_satoken_test_config(['timeout' => 3, 'auto_renew' => false]);

        $token = SaToken::login(self::TEST_USER_ID);

        $expireAt = SaToken::getTokenExpireTime($token);
        $this->assertIsInt($expireAt);
        $this->assertGreaterThan(time(), $expireAt);

        $remain = SaToken::getTokenRemainingTime($token);
        $this->assertIsInt($remain);
        $this->assertGreaterThan(0, $remain);
        $this->assertLessThanOrEqual(3, $remain);

        reset_satoken_test_config();
    }

    /**
     * 阈值续期：设置 renew_threshold=1 时，每次访问都应续期（即旧行为）
     */
    public function test_renew_threshold_1_always_renews_on_access()
    {
        set_satoken_test_config(['timeout' => 3, 'auto_renew' => true, 'renew_threshold' => 1]);

        $token = SaToken::login(self::TEST_USER_ID);

        // 稍等片刻让剩余时间减少
        usleep(200000);

        // 剩余时间 >= timeout * renew_threshold (= 3 * 1 = 3) 时不续期
        // 但 usleep 后剩余时间 < 3，所以应该触发续期
        $this->assertTrue(SaToken::isLogin($token));
        $remainAfter = SaToken::getTokenRemainingTime($token);
        $this->assertGreaterThanOrEqual(2, $remainAfter);

        reset_satoken_test_config();
    }

    /**
     * 阈值续期：剩余时间高于阈值时不应续期，避免每次请求写缓存
     */
    public function test_renew_below_threshold_does_not_rewrite_cache()
    {
        set_satoken_test_config(['timeout' => 10, 'auto_renew' => true, 'renew_threshold' => 0.3]);

        $token = SaToken::login(self::TEST_USER_ID);

        // 记录初始过期时间（刚登录，剩余 10s，远高于阈值 3s）
        $expireBefore = SaToken::getTokenExpireTime($token);

        // 等待 1 秒（剩余 ~9s，仍然高于阈值 3s）
        sleep(1);

        // 触发 isLogin，不应续期（剩余时间仍高于阈值）
        $this->assertTrue(SaToken::isLogin($token));

        // 过期时间应保持不变或只减少 ~1 秒（不应该被重置）
        $expireAfter = SaToken::getTokenExpireTime($token);
        $this->assertSame($expireBefore, $expireAfter);

        reset_satoken_test_config();
    }

    /**
     * 阈值续期：剩余时间低于阈值时才真正续期
     */
    public function test_renew_when_remaining_below_threshold_resets_ttl()
    {
        set_satoken_test_config(['timeout' => 3, 'auto_renew' => true, 'renew_threshold' => 0.5]);

        $token = SaToken::login(self::TEST_USER_ID);

        // 等待 2 秒，剩余 ~1s < 3 * 0.5 = 1.5s，应该触发续期
        sleep(2);

        $expireBefore = SaToken::getTokenExpireTime($token);
        $this->assertTrue(SaToken::isLogin($token));
        $expireAfter = SaToken::getTokenExpireTime($token);

        // 续期后过期时间应该被重置到当前时间 + timeout
        $this->assertGreaterThan($expireBefore, $expireAfter);
        $this->assertGreaterThanOrEqual(time() + 2, $expireAfter);

        reset_satoken_test_config();
    }

    /**
     * 滑动续期关闭时，isLogin不应重置过期时间
     */
    public function test_is_login_does_not_renew_when_disabled()
    {
        set_satoken_test_config(['timeout' => 3, 'auto_renew' => false]);

        $token = SaToken::login(self::TEST_USER_ID);

        $remain1 = SaToken::getTokenRemainingTime($token);
        $this->assertGreaterThan(0, $remain1);
        $this->assertLessThanOrEqual(3, $remain1);

        // 经过一段时间后调用isLogin，不应增加剩余时间
        sleep(1);
        $this->assertTrue(SaToken::isLogin($token));
        $remain2 = SaToken::getTokenRemainingTime($token);
        $this->assertLessThanOrEqual($remain1 - 1, $remain2);

        reset_satoken_test_config();
    }

    /**
     * 超时后剩余时间应为0，isLogin应返回false
     */
    public function test_remaining_time_zero_after_expiry()
    {
        set_satoken_test_config(['timeout' => 1, 'auto_renew' => false]);

        $token = SaToken::login(self::TEST_USER_ID);
        $this->assertTrue(SaToken::isLogin($token));

        sleep(2);
        $remain = SaToken::getTokenRemainingTime($token);
        $this->assertSame(0, $remain);

        $this->assertFalse(SaToken::isLogin($token));

        reset_satoken_test_config();
    }

    /**
     * 测试 createToken 方法是否返回符合 UUID 格式的字符串
     */
    public function test_create_token_returns_valid_uuid_format()
    {
        // 调用 createToken 方法
        $token = SaToken::createToken();

        // 验证返回值是否符合 UUID 格式
        $this->assertTrue(SaToken::validateTokenFormat($token));
    }

    public function test_create_token_is_pure_uuid_without_signature()
    {
        $token = SaToken::createToken();
        $this->assertTrue(SaToken::validateTokenFormat($token));
        $this->assertFalse(str_contains($token, '.'));
    }

    public function test_validate_token_format_rejects_uuid_with_signature()
    {
        $uuid = SaToken::createToken();
        $signed = $uuid.'.deadbeef';
        $this->assertTrue(SaToken::validateTokenFormat($uuid));
        $this->assertFalse(SaToken::validateTokenFormat($signed));
    }

    /**
     * 测试多次调用 createToken 方法是否返回不同的令牌
     */
    public function test_create_token_returns_different_values_on_multiple_calls()
    {
        // 生成多个令牌
        $token1 = SaToken::createToken();
        $token2 = SaToken::createToken();
        $token3 = SaToken::createToken();

        // 验证所有令牌都不相同
        $this->assertNotEquals($token1, $token2);
        $this->assertNotEquals($token1, $token3);
        $this->assertNotEquals($token2, $token3);

        // 更进一步：收集多个令牌并检查唯一性
        $tokens = [];
        $count = 100;
        for ($i = 0; $i < $count; $i++) {
            $tokens[] = SaToken::createToken();
        }

        // 转换为集合以移除重复项，然后检查数量是否保持不变
        $uniqueTokens = array_unique($tokens);
        $this->assertCount($count, $uniqueTokens);
    }

    /**
     * 测试登录功能是否返回有效的token
     */
    public function test_login_returns_valid_token()
    {
        // 执行登录
        $token = SaToken::login(self::TEST_USER_ID);

        // 验证返回值是否为非空字符串
        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        // 验证token已存入缓存
        $tokenKey = "satoken:token:$token";
        $tokenInfo = Cache::get($tokenKey);
        $this->assertNotEmpty($tokenInfo);
        $this->assertEquals(self::TEST_USER_ID, $tokenInfo['loginId']);

        // 验证loginId与token的映射关系已建立
        $loginIdKey = 'satoken:loginId:'.self::TEST_USER_ID;
        $storedToken = Cache::get($loginIdKey);
        $this->assertNotEmpty($storedToken);
    }

    /**
     * 测试登录时写入的 extra 能被正确保存与读取
     */
    public function test_login_with_extra_is_persisted_and_retrievable()
    {
        $extra = ['role' => 'admin', 'tenant_id' => 42];
        $token = SaToken::login(self::TEST_USER_ID, $extra);

        $info = SaToken::getTokenInfo($token);
        $this->assertArrayHasKey('extra', $info);
        $this->assertEquals($extra, $info['extra']);

        $this->assertEquals($extra, SaToken::getExtra($token));
    }

    /**
     * 测试未提供 extra 时默认返回空数组
     */
    public function test_login_without_extra_returns_empty_extra()
    {
        $token = SaToken::login(self::TEST_USER_ID);
        $extra = SaToken::getExtra($token);
        $this->assertIsArray($extra);
        $this->assertEmpty($extra);
    }

    /**
     * 测试滑动续期后 extra 不丢失
     */
    public function test_auto_renew_keeps_extra()
    {
        set_satoken_test_config(['auto_renew' => true, 'timeout' => 60]);
        $extra = ['scopes' => ['read', 'write']];
        $token = SaToken::login(self::TEST_USER_ID, $extra);

        $this->assertTrue(SaToken::isLogin($token));
        $this->assertEquals($extra, SaToken::getExtra($token));

        reset_satoken_test_config();
    }

    /**
     * 测试 setExtra 更新内容并保持原剩余有效期不变
     */
    public function test_set_extra_updates_content_and_preserves_ttl()
    {
        set_satoken_test_config(['timeout' => 3, 'auto_renew' => false]);
        $token = SaToken::login(self::TEST_USER_ID, ['k' => 'v']);
        $infoBefore = SaToken::getTokenInfo($token);
        $remainBefore = SaToken::getTokenRemainingTime($token);

        sleep(1);
        $ok = SaToken::setExtra($token, ['k' => 'updated', 'arr' => [1, 2]]);
        $this->assertTrue($ok);

        $infoAfter = SaToken::getTokenInfo($token);
        $this->assertEquals($infoBefore['expire_time'], $infoAfter['expire_time']);
        $this->assertEquals(['k' => 'updated', 'arr' => [1, 2]], SaToken::getExtra($token));

        $remainAfter = SaToken::getTokenRemainingTime($token);
        $this->assertGreaterThanOrEqual(1, $remainBefore - $remainAfter);

        reset_satoken_test_config();
    }

    /**
     * 测试过期后 setExtra 返回 false
     */
    public function test_set_extra_returns_false_when_expired()
    {
        set_satoken_test_config(['timeout' => 1, 'auto_renew' => false]);
        $token = SaToken::login(self::TEST_USER_ID);
        sleep(2);
        $this->assertFalse(SaToken::setExtra($token, ['x' => 1]));
        reset_satoken_test_config();
    }

    /**
     * 测试登出功能是否正确移除token
     */
    public function test_logout_removes_token()
    {
        // 先登录获取token
        $token = SaToken::login(self::TEST_USER_ID);

        // 验证登录状态
        $this->assertTrue(SaToken::isLogin($token));

        // 执行登出
        $result = SaToken::logout($token);

        // 验证登出成功
        $this->assertTrue($result);

        // 验证token已从缓存移除
        $tokenKey = "satoken:token:$token";
        $this->assertFalse(Cache::has($tokenKey));

        // 验证登录状态已失效
        $this->assertFalse(SaToken::isLogin($token));
    }

    /**
     * 测试登录后isLogin应返回true
     */
    public function test_is_login_after_login()
    {
        // 先登录获取token
        $token = SaToken::login(self::TEST_USER_ID);

        // 验证isLogin返回true
        $this->assertTrue(SaToken::isLogin($token));

        // 测试使用不同token的情况
        $anotherToken = SaToken::login(self::ANOTHER_USER_ID);
        $this->assertTrue(SaToken::isLogin($anotherToken));
        // 确保两个token都有效
        $this->assertTrue(SaToken::isLogin($token));
    }

    /**
     * 测试未登录或无效token时isLogin应返回false
     */
    public function test_is_not_login_for_invalid_token()
    {
        // 使用无效token
        $invalidToken = 'invalid-token-123456';
        $this->assertFalse(SaToken::isLogin($invalidToken));

        // 使用空token
        $this->assertFalse(SaToken::isLogin(null));
        $this->assertFalse(SaToken::isLogin(''));
    }

    /**
     * 测试登出后isLogin应返回false
     */
    public function test_is_not_login_after_logout()
    {
        // 先登录获取token
        $token = SaToken::login(self::TEST_USER_ID);

        // 验证登录状态
        $this->assertTrue(SaToken::isLogin($token));

        // 执行登出
        SaToken::logout($token);

        // 验证登录状态已失效
        $this->assertFalse(SaToken::isLogin($token));
    }

    /**
     * 测试getCurrentLoginId应返回正确的登录ID
     */
    public function test_get_current_login_id_returns_correct_id()
    {
        // 先登录获取token
        $token = SaToken::login(self::TEST_USER_ID);

        // 验证getCurrentLoginId返回正确的登录ID
        $loginId = SaToken::getCurrentLoginId($token);
        $this->assertEquals(self::TEST_USER_ID, $loginId);

        // 使用不同用户登录，验证能正确获取对应的登录ID
        $anotherToken = SaToken::login(self::ANOTHER_USER_ID);
        $anotherLoginId = SaToken::getCurrentLoginId($anotherToken);
        $this->assertEquals(self::ANOTHER_USER_ID, $anotherLoginId);
        // 确保两个token都能正确获取对应的登录ID
        $this->assertEquals(self::TEST_USER_ID, SaToken::getCurrentLoginId($token));
    }

    /**
     * 测试未提供token时getCurrentLoginId应抛出NotLoginException异常
     */
    public function test_get_current_login_id_throws_exception_for_no_token()
    {
        $this->expectException(NotLoginException::class);
        SaToken::getCurrentLoginId(null);
    }

    /**
     * 测试无效token格式时getCurrentLoginId应抛出TokenInvalidException异常
     */
    public function test_get_current_login_id_throws_exception_for_invalid_token_format()
    {
        // 测试各种无效的token格式
        $invalidFormats = [
            'not-a-uuid',
            '12345678',
            '12345678-1234',
            '12345678-1234-1234',
            '12345678-1234-1234-1234',
            '12345678-1234-1234-1234-1234567890abx', // 太长
            '1234567-1234-1234-1234-1234567890ab', // 太短
            '12345678-1234-6234-1234-1234567890ab', // 版本号不符合
            '12345678-1234-1234-7234-1234567890ab', // 变体不符合
            '12345678-1234-1234-1234-1234567890ab_append', // 附加内容
        ];

        foreach ($invalidFormats as $invalidToken) {
            try {
                SaToken::getCurrentLoginId($invalidToken);
                $this->fail("Expected TokenInvalidException for token: $invalidToken");
            } catch (TokenInvalidException $e) {
                $this->assertEquals('无效的token格式', $e->getMessage());
            }
        }
    }

    /**
     * 测试强制踢出用户功能
     */
    public function test_kickout_removes_token_and_logs_out_user()
    {
        // 先登录获取token
        $token = SaToken::login(self::TEST_USER_ID);

        // 验证登录状态
        $this->assertTrue(SaToken::isLogin($token));

        // 执行强制踢出操作
        $result = SaToken::kickout($token);

        // 验证踢出成功
        $this->assertTrue($result);

        // 验证token已从缓存移除
        $tokenKey = "satoken:token:$token";
        $this->assertFalse(Cache::has($tokenKey));

        // 验证登录状态已失效
        $this->assertFalse(SaToken::isLogin($token));

        // 验证getCurrentLoginId应抛出异常
        $this->expectException(TokenInvalidException::class);
        SaToken::getCurrentLoginId($token);
    }

    /**
     * 测试强制踢出无效token的处理
     */
    public function test_kickout_for_invalid_token()
    {
        // 使用无效token
        $invalidToken = 'invalid-token-123456';

        // 执行强制踢出操作
        $result = SaToken::kickout($invalidToken);

        // 验证踢出失败
        $this->assertFalse($result);
    }

    /**
     * 测试多个token情况下的强制踢出功能
     */
    public function test_kickout_does_not_affect_other_tokens()
    {
        // 用户1登录获取两个token
        $token1 = SaToken::login(self::TEST_USER_ID);
        $token2 = SaToken::login(self::TEST_USER_ID);

        // 用户2登录获取token
        $anotherToken = SaToken::login(self::ANOTHER_USER_ID);

        // 验证所有token都有效
        $this->assertTrue(SaToken::isLogin($token1));
        $this->assertTrue(SaToken::isLogin($token2));
        $this->assertTrue(SaToken::isLogin($anotherToken));

        // 强制踢出第一个token
        $result = SaToken::kickout($token1);
        $this->assertTrue($result);

        // 验证第一个token已失效
        $this->assertFalse(SaToken::isLogin($token1));

        // 验证其他token仍然有效
        $this->assertTrue(SaToken::isLogin($token2));
        $this->assertTrue(SaToken::isLogin($anotherToken));
    }

    /**
     * 非并发模式：后一次登录应替换前一次登录
     */
    public function test_non_concurrent_replaces_previous_login()
    {
        set_satoken_test_config(['is_concurrent' => false, 'timeout' => 60]);

        $t1 = SaToken::login(self::TEST_USER_ID);
        $this->assertTrue(SaToken::isLogin($t1));

        $t2 = SaToken::login(self::TEST_USER_ID);
        $this->assertTrue(SaToken::isLogin($t2));
        $this->assertFalse(SaToken::isLogin($t1));

        $loginIdKey = 'satoken:loginId:'.self::TEST_USER_ID;
        $this->assertSame($t2, Cache::get($loginIdKey));

        reset_satoken_test_config();
    }

    /**
     * 非并发模式：历史并发映射为数组时，登录应清理旧数组中的所有令牌
     */
    public function test_non_concurrent_cleans_array_mapping_on_mode_switch()
    {
        set_satoken_test_config(['is_concurrent' => false, 'timeout' => 60]);

        $loginId = self::TEST_USER_ID;
        $t1 = SaToken::createToken();
        $t2 = SaToken::createToken();
        $loginIdKey = 'satoken:loginId:'.$loginId;

        Cache::set("satoken:token:$t1", ['loginId' => $loginId], 60);
        Cache::set("satoken:token:$t2", ['loginId' => $loginId], 60);
        Cache::set($loginIdKey, [$t1, $t2], 60);

        $newToken = SaToken::login($loginId);

        $this->assertFalse(Cache::has("satoken:token:$t1"));
        $this->assertFalse(Cache::has("satoken:token:$t2"));
        $this->assertSame($newToken, Cache::get($loginIdKey));

        reset_satoken_test_config();
    }

    /**
     * 非并发模式：isLogin在映射缺失时应自动重建映射
     */
    public function test_is_login_rebuilds_mapping_when_missing_non_concurrent()
    {
        set_satoken_test_config(['is_concurrent' => false, 'timeout' => 60]);

        $t = SaToken::login(self::TEST_USER_ID);
        $loginIdKey = 'satoken:loginId:'.self::TEST_USER_ID;
        Cache::delete($loginIdKey);

        $this->assertTrue(SaToken::isLogin($t));
        $this->assertSame($t, Cache::get($loginIdKey));

        reset_satoken_test_config();
    }

    /**
     * 并发模式：超过最大登录数量时移除最早令牌
     */
    public function test_concurrent_respects_max_login_count_and_removes_oldest()
    {
        set_satoken_test_config(['is_concurrent' => true, 'max_login_count' => 2, 'timeout' => 60]);

        $t1 = SaToken::login(self::TEST_USER_ID);
        $t2 = SaToken::login(self::TEST_USER_ID);
        $t3 = SaToken::login(self::TEST_USER_ID);

        $this->assertFalse(SaToken::isLogin($t1));
        $this->assertTrue(SaToken::isLogin($t2));
        $this->assertTrue(SaToken::isLogin($t3));

        $loginIdKey = 'satoken:loginId:'.self::TEST_USER_ID;
        $list = Cache::get($loginIdKey);
        $this->assertIsArray($list);
        $this->assertCount(2, $list);
        $this->assertSame([$t2, $t3], array_values($list));

        reset_satoken_test_config();
    }

    /**
     * 测试前的设置
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 每次测试前清除缓存，确保测试隔离性
        Cache::clear();
    }
}