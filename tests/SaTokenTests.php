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

    // =================== checkLogin 测试用例 ===================

    /**
     * checkLogin：有效token应不抛出任何异常
     */
    public function test_check_login_passes_for_valid_token()
    {
        $token = SaToken::login(self::TEST_USER_ID);

        $exception = null;
        try {
            SaToken::checkLogin($token);
        } catch (\Throwable $e) {
            $exception = $e;
        }

        $this->assertNull($exception, 'checkLogin 对有效 token 不应抛出异常，实际抛出：'
            . ($exception ? get_class($exception).': '.$exception->getMessage() : 'null'));
    }

    /**
     * checkLogin：未提供token应抛出 NotLoginException
     */
    public function test_check_login_throws_not_login_exception_when_no_token()
    {
        $this->expectException(NotLoginException::class);
        SaToken::checkLogin(null);
    }

    /**
     * checkLogin：空字符串token同样应视为未提供
     */
    public function test_check_login_throws_not_login_exception_for_empty_string_token()
    {
        $this->expectException(NotLoginException::class);
        SaToken::checkLogin('');
    }

    /**
     * checkLogin：格式错误应抛出 TokenInvalidException（无效的token格式）
     */
    public function test_check_login_throws_token_invalid_exception_for_bad_format()
    {
        $caught = false;
        try {
            SaToken::checkLogin('not-a-valid-token');
        } catch (TokenInvalidException $e) {
            $this->assertEquals('无效的token格式', $e->getMessage());
            $caught = true;
        }

        $this->assertTrue($caught, 'checkLogin 对格式错误的 token 应抛出 TokenInvalidException("无效的token格式")');
    }

    /**
     * checkLogin：格式正确但缓存中不存在（伪造的UUID）应抛出 TokenInvalidException（无效的token）
     */
    public function test_check_login_throws_token_invalid_exception_for_unknown_token()
    {
        $fakeToken = SaToken::createToken(); // 格式合法但从未登录过

        $caught = false;
        try {
            SaToken::checkLogin($fakeToken);
        } catch (TokenInvalidException $e) {
            $this->assertEquals('无效的token', $e->getMessage());
            $caught = true;
        }

        $this->assertTrue($caught, 'checkLogin 对格式合法但不存在的 token 应抛出 TokenInvalidException("无效的token")');
    }

    /**
     * checkLogin：已登出/踢出的token应抛出 TokenInvalidException
     */
    public function test_check_login_throws_token_invalid_exception_after_logout()
    {
        $token = SaToken::login(self::TEST_USER_ID);
        SaToken::logout($token);

        $this->expectException(TokenInvalidException::class);
        SaToken::checkLogin($token);
    }

    /**
     * checkLogin：过期后应抛出 TokenInvalidException
     */
    public function test_check_login_throws_token_invalid_exception_after_expiry()
    {
        set_satoken_test_config(['timeout' => 1, 'auto_renew' => false]);

        $token = SaToken::login(self::TEST_USER_ID);
        sleep(2);

        $this->expectException(TokenInvalidException::class);
        SaToken::checkLogin($token);

        reset_satoken_test_config();
    }

    /**
     * checkLogin：token信息中缺少loginId应抛出 TokenInvalidException（token信息不完整）
     */
    public function test_check_login_throws_token_invalid_exception_for_missing_login_id()
    {
        $token = SaToken::createToken();
        $tokenKey = "satoken:token:$token";
        // 手动塞入缺少 loginId 的信息
        Cache::set($tokenKey, ['extra' => ['role' => 'admin'], 'create_time' => time()], 60);

        $caught = false;
        try {
            SaToken::checkLogin($token);
        } catch (TokenInvalidException $e) {
            $this->assertEquals('token信息不完整', $e->getMessage());
            $caught = true;
        }

        $this->assertTrue($caught, 'checkLogin 对缺少 loginId 的 token 应抛出 TokenInvalidException("token信息不完整")');
    }

    /**
     * checkLogin：不同用户的 token 都能被正确校验
     */
    public function test_check_login_works_for_multiple_users()
    {
        $tokenA = SaToken::login(self::TEST_USER_ID);
        $tokenB = SaToken::login(self::ANOTHER_USER_ID);

        $exception = null;
        try {
            SaToken::checkLogin($tokenA);
            SaToken::checkLogin($tokenB);
        } catch (\Throwable $e) {
            $exception = $e;
        }

        $this->assertNull($exception, 'checkLogin 对多个用户的有效 token 不应抛出异常');
    }

    /**
     * checkLogin：通过校验后应触发滑动续期（剩余时间低于阈值时刷新 TTL）
     */
    public function test_check_login_triggers_renew_when_below_threshold()
    {
        set_satoken_test_config(['timeout' => 3, 'auto_renew' => true, 'renew_threshold' => 0.8]);

        $token = SaToken::login(self::TEST_USER_ID);
        $expireBefore = SaToken::getTokenExpireTime($token);

        sleep(2); // 剩余时间约 1s，低于 3*0.8=2.4s 阈值

        SaToken::checkLogin($token);

        $expireAfter = SaToken::getTokenExpireTime($token);
        $this->assertGreaterThan($expireBefore, $expireAfter,
            'checkLogin 通过校验后应触发滑动续期，expire_time 应被刷新');

        reset_satoken_test_config();
    }

    /**
     * checkLogin：与 isLogin 保持一致的成功/失败判定（同一token两边结果应相同）
     */
    public function test_check_login_consistent_with_is_login()
    {
        $validToken = SaToken::login(self::TEST_USER_ID);
        $invalidToken = SaToken::createToken();

        // isLogin=true 的 token，checkLogin 也应通过
        $this->assertTrue(SaToken::isLogin($validToken));
        $thrown = null;
        try {
            SaToken::checkLogin($validToken);
        } catch (\Throwable $e) {
            $thrown = $e;
        }
        $this->assertNull($thrown, 'isLogin 为 true 的 token，checkLogin 也应通过');

        // isLogin=false 的 token，checkLogin 也应抛异常
        $this->assertFalse(SaToken::isLogin($invalidToken));
        $thrown = null;
        try {
            SaToken::checkLogin($invalidToken);
        } catch (\Throwable $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown, 'isLogin 为 false 的 token，checkLogin 应抛出异常');
    }

    /**
     * 测试按 loginId 踢出用户所有 token
     */
    public function test_kickout_by_id_removes_all_tokens_of_user()
    {
        // 用户1登录获取3个token
        $token1 = SaToken::login(self::TEST_USER_ID);
        $token2 = SaToken::login(self::TEST_USER_ID);
        $token3 = SaToken::login(self::TEST_USER_ID);

        // 用户2登录获取token
        $anotherToken = SaToken::login(self::ANOTHER_USER_ID);

        // 验证所有token都有效
        $this->assertTrue(SaToken::isLogin($token1));
        $this->assertTrue(SaToken::isLogin($token2));
        $this->assertTrue(SaToken::isLogin($token3));
        $this->assertTrue(SaToken::isLogin($anotherToken));

        // 按 loginId 踢出用户1的所有 token
        $result = SaToken::kickout(self::TEST_USER_ID);
        $this->assertTrue($result);

        // 验证用户1的所有token已失效
        $this->assertFalse(SaToken::isLogin($token1));
        $this->assertFalse(SaToken::isLogin($token2));
        $this->assertFalse(SaToken::isLogin($token3));

        // 验证用户2的token仍然有效
        $this->assertTrue(SaToken::isLogin($anotherToken));

        // 验证 loginIdKey 已被清理
        $loginIdKey = 'satoken:loginId:'.self::TEST_USER_ID;
        $this->assertFalse(Cache::has($loginIdKey));
    }

    /**
     * 测试按 loginId 踢出不存在的用户应返回 false
     */
    public function test_kickout_by_id_returns_false_for_unknown_user()
    {
        $result = SaToken::kickout(9999);
        $this->assertFalse($result);
    }

    /**
     * 测试 kickoutByToken：踢出单个 token
     */
    public function test_kickout_by_token_removes_token_and_logs_out_user()
    {
        // 先登录获取token
        $token = SaToken::login(self::TEST_USER_ID);

        // 验证登录状态
        $this->assertTrue(SaToken::isLogin($token));

        // 执行强制踢出操作
        $result = SaToken::kickoutByToken($token);

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
     * 测试 kickoutByToken：无效token的处理
     */
    public function test_kickout_by_token_for_invalid_token()
    {
        // 使用无效token
        $invalidToken = 'invalid-token-123456';

        // 执行强制踢出操作
        $result = SaToken::kickoutByToken($invalidToken);

        // 验证踢出失败
        $this->assertFalse($result);
    }

    /**
     * 测试 kickoutByToken：不会影响其他 token（包括同一用户的其他 token）
     */
    public function test_kickout_by_token_does_not_affect_other_tokens()
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
        $result = SaToken::kickoutByToken($token1);
        $this->assertTrue($result);

        // 验证第一个token已失效
        $this->assertFalse(SaToken::isLogin($token1));

        // 验证同一用户的其他token仍然有效
        $this->assertTrue(SaToken::isLogin($token2));

        // 验证其他用户的token仍然有效
        $this->assertTrue(SaToken::isLogin($anotherToken));
    }

    /**
     * 非并发模式：后一次登录应替换前一次登录（统一用数组存储）
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
        $stored = Cache::get($loginIdKey);
        $this->assertIsArray($stored);
        $this->assertSame([$t2], array_values($stored));

        reset_satoken_test_config();
    }

    /**
     * 非并发模式：历史并发映射为数组时，登录应清理旧数组中的所有令牌（统一用数组存储）
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
        $stored = Cache::get($loginIdKey);
        $this->assertIsArray($stored);
        $this->assertSame([$newToken], array_values($stored));

        reset_satoken_test_config();
    }

    /**
     * 非并发模式：isLogin在映射缺失时应自动重建映射（统一用数组存储）
     */
    public function test_is_login_rebuilds_mapping_when_missing_non_concurrent()
    {
        set_satoken_test_config(['is_concurrent' => false, 'timeout' => 60]);

        $t = SaToken::login(self::TEST_USER_ID);
        $loginIdKey = 'satoken:loginId:'.self::TEST_USER_ID;
        Cache::delete($loginIdKey);

        $this->assertTrue(SaToken::isLogin($t));
        $stored = Cache::get($loginIdKey);
        $this->assertIsArray($stored);
        $this->assertSame([$t], array_values($stored));

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
     * 改进1：登录时自动清理过期 token，不会把已过期的 token 算入配额
     * 场景：max_login_count=2，先用两个 token 登录，手动让其中一个过期，再登录新 token
     * 预期：已过期 token 不占用配额，其他仍有效 token 不会被踢出
     */
    public function test_login_cleans_expired_tokens_and_does_not_kick_valid_ones()
    {
        set_satoken_test_config(['is_concurrent' => true, 'max_login_count' => 2, 'timeout' => 60]);

        $loginId = self::TEST_USER_ID;
        $loginIdKey = 'satoken:loginId:'.$loginId;

        // 1. 先登录两个 token
        $t1 = SaToken::login($loginId);
        $t2 = SaToken::login($loginId);
        $this->assertTrue(SaToken::isLogin($t1));
        $this->assertTrue(SaToken::isLogin($t2));

        // 2. 手动让 t1 过期（删除其 tokenKey，模拟自然过期）
        Cache::delete("satoken:token:$t1");

        // 3. 现在登录新的 token t3
        $t3 = SaToken::login($loginId);

        // 4. 因为 t1 已过期，清理后列表只剩 [t2]，添加 t3 后为 [t2, t3]
        //    t2 不应被踢出
        $this->assertTrue(SaToken::isLogin($t2));
        $this->assertTrue(SaToken::isLogin($t3));

        $list = Cache::get($loginIdKey);
        $this->assertIsArray($list);
        $this->assertCount(2, $list);
        $this->assertSame([$t2, $t3], array_values($list));

        reset_satoken_test_config();
    }

    /**
     * 改进1（变体）：多个过期 token 堆积时，登录后应清理全部过期 token
     * 场景：max_login_count=3，先用3个 token 登录，让其中2个过期，再登录2个新 token
     * 预期：2个过期 token 被清理，3个有效 token 保留，不发生误踢出
     */
    public function test_login_cleans_multiple_expired_tokens_from_list()
    {
        set_satoken_test_config(['is_concurrent' => true, 'max_login_count' => 3, 'timeout' => 60]);

        $loginId = self::TEST_USER_ID;
        $loginIdKey = 'satoken:loginId:'.$loginId;

        $t1 = SaToken::login($loginId);
        $t2 = SaToken::login($loginId);
        $t3 = SaToken::login($loginId);

        // 让 t1 和 t2 过期
        Cache::delete("satoken:token:$t1");
        Cache::delete("satoken:token:$t2");

        // 登录两个新 token
        $t4 = SaToken::login($loginId);
        $t5 = SaToken::login($loginId);

        // t3 仍然有效，不应被踢出
        $this->assertTrue(SaToken::isLogin($t3));
        $this->assertTrue(SaToken::isLogin($t4));
        $this->assertTrue(SaToken::isLogin($t5));

        $list = Cache::get($loginIdKey);
        $this->assertIsArray($list);
        $this->assertCount(3, $list);
        $this->assertSame([$t3, $t4, $t5], array_values($list));

        reset_satoken_test_config();
    }

    /**
     * 改进2：并发模式下 loginIdKey 丢失后，isLogin 也能自动重建映射
     * 场景：并发模式下登录2个 token，手动删除 loginIdKey，然后用其中一个 token 访问 isLogin
     * 预期：loginIdKey 被重建为包含该 token 的列表，另一个 token 需重新访问才会被加入
     */
    public function test_concurrent_islogin_rebuilds_mapping_when_loginidkey_missing()
    {
        set_satoken_test_config(['is_concurrent' => true, 'max_login_count' => 5, 'timeout' => 60]);

        $loginId = self::TEST_USER_ID;
        $loginIdKey = 'satoken:loginId:'.$loginId;

        $t1 = SaToken::login($loginId);
        $t2 = SaToken::login($loginId);

        // 删除反向映射
        Cache::delete($loginIdKey);
        $this->assertFalse(Cache::has($loginIdKey));

        // 用 t1 访问 isLogin，应重建映射
        $this->assertTrue(SaToken::isLogin($t1));

        $stored = Cache::get($loginIdKey);
        $this->assertIsArray($stored);
        $this->assertContains($t1, $stored);

        // t1 自身仍然有效
        $this->assertTrue(SaToken::isLogin($t1));
        // t2 的 tokenKey 仍然存在，所以仍然有效
        $this->assertTrue(SaToken::isLogin($t2));

        reset_satoken_test_config();
    }

    /**
     * 改进3：兼容历史字符串格式的 loginIdKey（旧版本非并发模式存字符串）
     * 场景：手动构造一个旧格式的 loginIdKey（存储字符串 token），然后调用 logout/kickout
     * 预期：能正确读取、清理，并最终转换为数组格式
     */
    public function test_login_compatible_with_legacy_string_loginidkey()
    {
        set_satoken_test_config(['is_concurrent' => true, 'max_login_count' => 3, 'timeout' => 60]);

        $loginId = self::TEST_USER_ID;
        $loginIdKey = 'satoken:loginId:'.$loginId;

        // 模拟旧版本：loginIdKey 存字符串（非并发模式遗留）
        $legacyToken = SaToken::createToken();
        Cache::set("satoken:token:$legacyToken", ['loginId' => $loginId, 'expire_time' => time() + 60], 60);
        Cache::set($loginIdKey, $legacyToken, 60);

        // 用改进后的代码登录新 token
        $t1 = SaToken::login($loginId);

        // legacyToken 因为 tokenKey 仍有效，应被保留在列表中
        $list = Cache::get($loginIdKey);
        $this->assertIsArray($list);
        $this->assertContains($legacyToken, $list);
        $this->assertContains($t1, $list);

        reset_satoken_test_config();
    }

    /**
     * 改进4：is_concurrent=false 等价于 max_login_count=1
     * 场景：设置 is_concurrent=false 或 max_login_count=1，两种配置应表现一致
     */
    public function test_non_concurrent_is_equivalent_to_max_login_count_one()
    {
        $loginId = self::TEST_USER_ID;
        $loginIdKey = 'satoken:loginId:'.$loginId;

        // 方案 A：is_concurrent=false
        set_satoken_test_config(['is_concurrent' => false, 'timeout' => 60]);
        $a1 = SaToken::login($loginId);
        $a2 = SaToken::login($loginId);
        $this->assertFalse(SaToken::isLogin($a1));
        $this->assertTrue(SaToken::isLogin($a2));
        $listA = Cache::get($loginIdKey);
        $this->assertIsArray($listA);
        $this->assertCount(1, $listA);
        reset_satoken_test_config();

        // 清理缓存
        Cache::clear();

        // 方案 B：is_concurrent=true, max_login_count=1
        set_satoken_test_config(['is_concurrent' => true, 'max_login_count' => 1, 'timeout' => 60]);
        $b1 = SaToken::login($loginId);
        $b2 = SaToken::login($loginId);
        $this->assertFalse(SaToken::isLogin($b1));
        $this->assertTrue(SaToken::isLogin($b2));
        $listB = Cache::get($loginIdKey);
        $this->assertIsArray($listB);
        $this->assertCount(1, $listB);
        reset_satoken_test_config();
    }

    /**
     * 改进5：逐个踢出 token 后，loginIdKey 被正确更新
     * 场景：并发模式下登录3个 token，逐个踢出，验证 loginIdKey 的内容
     */
    public function test_kickout_sequentially_updates_loginidkey_correctly()
    {
        set_satoken_test_config(['is_concurrent' => true, 'max_login_count' => 5, 'timeout' => 60]);

        $loginId = self::TEST_USER_ID;
        $loginIdKey = 'satoken:loginId:'.$loginId;

        $t1 = SaToken::login($loginId);
        $t2 = SaToken::login($loginId);
        $t3 = SaToken::login($loginId);

        // 踢出 t2
        SaToken::kickoutByToken($t2);
        $this->assertFalse(SaToken::isLogin($t2));

        $list = Cache::get($loginIdKey);
        $this->assertIsArray($list);
        $this->assertCount(2, $list);
        $this->assertContains($t1, $list);
        $this->assertContains($t3, $list);
        $this->assertNotContains($t2, $list);

        // 踢出 t1
        SaToken::kickoutByToken($t1);
        $list = Cache::get($loginIdKey);
        $this->assertIsArray($list);
        $this->assertCount(1, $list);
        $this->assertSame([$t3], array_values($list));

        // 踢出最后一个
        SaToken::kickoutByToken($t3);
        $this->assertFalse(Cache::has($loginIdKey));

        reset_satoken_test_config();
    }

    /**
     * 改进6：loginIdKey 中的列表与实际有效 token 保持一致
     * 场景：手动在 loginIdKey 列表中塞入不存在的 token，登录新 token 后应被清理
     */
    public function test_login_removes_non_existent_tokens_from_loginidkey_list()
    {
        set_satoken_test_config(['is_concurrent' => true, 'max_login_count' => 3, 'timeout' => 60]);

        $loginId = self::TEST_USER_ID;
        $loginIdKey = 'satoken:loginId:'.$loginId;

        // 手动构造一个包含"幽灵 token"的列表（这些 token 的 tokenKey 不存在）
        $ghost1 = SaToken::createToken();
        $ghost2 = SaToken::createToken();
        $validToken = SaToken::login($loginId);

        // 手动往列表中塞入幽灵 token
        Cache::set($loginIdKey, [$ghost1, $ghost2, $validToken], 60);

        // 登录新 token
        $newToken = SaToken::login($loginId);

        // 登录后 ghost1 和 ghost2 应被清理，只有 validToken 和 newToken 保留
        $list = Cache::get($loginIdKey);
        $this->assertIsArray($list);
        $this->assertCount(2, $list);
        $this->assertNotContains($ghost1, $list);
        $this->assertNotContains($ghost2, $list);
        $this->assertContains($validToken, $list);
        $this->assertContains($newToken, $list);

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