<?php

namespace satoken\tests;

use satoken\exception\NotLoginException;
use satoken\exception\TokenInvalidException;
use satoken\facade\SaToken;
use think\facade\Cache;
use Throwable;

/**
 * SaToken 单元测试
 */
class SaTokenTests extends ThinkTestCase
{
    public const TEST_USER_ID = 1001;

    public const ANOTHER_USER_ID = 1002;

    /**
     * 测试 createToken 方法是否返回非空字符串
     */
    public function test_create_token_returns_non_empty_string()
    {
        $token = SaToken::createToken();

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
     * renew_before=0 时，每次访问都应续期
     */
    public function test_renew_before_zero_always_renews_on_access()
    {
        set_satoken_test_config(['timeout' => 3, 'auto_renew' => true, 'renew_before' => 0]);

        $token = SaToken::login(self::TEST_USER_ID);

        usleep(200000);

        $this->assertTrue(SaToken::isLogin($token));
        $remainAfter = SaToken::getTokenRemainingTime($token);
        $this->assertGreaterThanOrEqual(2, $remainAfter);

        reset_satoken_test_config();
    }

    /**
     * 剩余时间高于 renew_before 时不应续期，避免每次请求写缓存
     */
    public function test_renew_above_before_does_not_rewrite_cache()
    {
        set_satoken_test_config(['timeout' => 10, 'auto_renew' => true, 'renew_before' => 3]);

        $token = SaToken::login(self::TEST_USER_ID);

        $expireBefore = SaToken::getTokenExpireTime($token);

        // 等待 1 秒（剩余 ~9s，仍然高于 renew_before 3s）
        sleep(1);

        $this->assertTrue(SaToken::isLogin($token));

        $expireAfter = SaToken::getTokenExpireTime($token);
        $this->assertSame($expireBefore, $expireAfter);

        reset_satoken_test_config();
    }

    /**
     * 剩余时间低于 renew_before 时才真正续期
     */
    public function test_renew_when_remaining_below_before_resets_ttl()
    {
        set_satoken_test_config(['timeout' => 3, 'auto_renew' => true, 'renew_before' => 2]);

        $token = SaToken::login(self::TEST_USER_ID);

        // 等待 2 秒，剩余 ~1s < renew_before 2s，应该触发续期
        sleep(2);

        $expireBefore = SaToken::getTokenExpireTime($token);
        $this->assertTrue(SaToken::isLogin($token));
        $expireAfter = SaToken::getTokenExpireTime($token);

        $this->assertGreaterThan($expireBefore, $expireAfter);
        $this->assertGreaterThanOrEqual(time() + 2, $expireAfter);

        reset_satoken_test_config();
    }

    /**
     * 滑动续期关闭时，isLogin 不应重置过期时间
     */
    public function test_is_login_does_not_renew_when_disabled()
    {
        set_satoken_test_config(['timeout' => 3, 'auto_renew' => false]);

        $token = SaToken::login(self::TEST_USER_ID);

        $remain1 = SaToken::getTokenRemainingTime($token);
        $this->assertGreaterThan(0, $remain1);
        $this->assertLessThanOrEqual(3, $remain1);

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
        $token = SaToken::createToken();

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
        $uuid   = SaToken::createToken();
        $signed = $uuid.'.deadbeef';
        $this->assertTrue(SaToken::validateTokenFormat($uuid));
        $this->assertFalse(SaToken::validateTokenFormat($signed));
    }

    /**
     * 测试多次调用 createToken 方法是否返回不同的令牌
     */
    public function test_create_token_returns_different_values_on_multiple_calls()
    {
        $token1 = SaToken::createToken();
        $token2 = SaToken::createToken();
        $token3 = SaToken::createToken();

        $this->assertNotEquals($token1, $token2);
        $this->assertNotEquals($token1, $token3);
        $this->assertNotEquals($token2, $token3);

        $tokens = [];
        $count  = 100;
        for ($i = 0; $i < $count; $i++) {
            $tokens[] = SaToken::createToken();
        }

        $uniqueTokens = array_unique($tokens);
        $this->assertCount($count, $uniqueTokens);
    }

    /**
     * 测试登录功能是否返回有效的token
     */
    public function test_login_returns_valid_token()
    {
        $token = SaToken::login(self::TEST_USER_ID);

        $this->assertIsString($token);
        $this->assertNotEmpty($token);

        // 验证token已存入缓存
        $tokenKey  = "satoken:token:$token";
        $tokenInfo = Cache::get($tokenKey);
        $this->assertNotEmpty($tokenInfo);
        $this->assertEquals(self::TEST_USER_ID, $tokenInfo['loginId']);

        // 验证 loginId -> token 列表映射关系已建立（列表中包含该 token）
        $loginIdKey = 'satoken:loginId:'.self::TEST_USER_ID;
        $storedList = Cache::get($loginIdKey);
        $this->assertIsArray($storedList);
        $this->assertContains($token, $storedList);
    }

    /**
     * 测试多 Token 在线：同一用户多次登录，所有 token 都有效，互不顶号（在 max_login_count 内）
     */
    public function test_multiple_logins_keep_all_tokens_valid_within_max()
    {
        set_satoken_test_config(['max_login_count' => 5]);

        $t1 = SaToken::login(self::TEST_USER_ID);
        $t2 = SaToken::login(self::TEST_USER_ID);
        $t3 = SaToken::login(self::TEST_USER_ID);

        // 3 个 token 都应当有效
        $this->assertTrue(SaToken::isLogin($t1), '第 1 次登录的 token 应仍然有效');
        $this->assertTrue(SaToken::isLogin($t2), '第 2 次登录的 token 应仍然有效');
        $this->assertTrue(SaToken::isLogin($t3), '第 3 次登录的 token 应仍然有效');

        // 3 个 token 指向同一个 loginId
        $this->assertSame(self::TEST_USER_ID, SaToken::getCurrentLoginId($t1));
        $this->assertSame(self::TEST_USER_ID, SaToken::getCurrentLoginId($t2));
        $this->assertSame(self::TEST_USER_ID, SaToken::getCurrentLoginId($t3));

        reset_satoken_test_config();
    }

    /**
     * 测试 max_login_count 限制：超出时最早的 token 被顶掉，后面的保留
     */
    public function test_exceeding_max_login_count_kicks_out_oldest()
    {
        set_satoken_test_config(['max_login_count' => 3]);

        $t1 = SaToken::login(self::TEST_USER_ID);
        $t2 = SaToken::login(self::TEST_USER_ID);
        $t3 = SaToken::login(self::TEST_USER_ID);
        // 第 4 次登录应顶掉 t1（最早的）
        $t4 = SaToken::login(self::TEST_USER_ID);

        $this->assertFalse(SaToken::isLogin($t1), '超出上限后最早的 t1 应失效');
        $this->assertTrue(SaToken::isLogin($t2), 't2 应仍然有效');
        $this->assertTrue(SaToken::isLogin($t3), 't3 应仍然有效');
        $this->assertTrue(SaToken::isLogin($t4), 't4 应仍然有效');

        // 列表中只保留 3 个（t2, t3, t4）
        $loginIdKey = 'satoken:loginId:'.self::TEST_USER_ID;
        $storedList = Cache::get($loginIdKey);
        $this->assertCount(3, $storedList);
        $this->assertNotContains($t1, $storedList);

        reset_satoken_test_config();
    }

    /**
     * 测试登出 A token 不影响同账号的 B token（多端友好）
     */
    public function test_logout_one_token_does_not_affect_others()
    {
        set_satoken_test_config(['max_login_count' => 10]);

        $phone = SaToken::login(self::TEST_USER_ID);
        $pc    = SaToken::login(self::TEST_USER_ID);
        $pad   = SaToken::login(self::TEST_USER_ID);

        $this->assertTrue(SaToken::isLogin($phone));
        $this->assertTrue(SaToken::isLogin($pc));
        $this->assertTrue(SaToken::isLogin($pad));

        // 手机端登出
        SaToken::logout($phone);

        $this->assertFalse(SaToken::isLogin($phone), '手机端登出后该 token 应失效');
        $this->assertTrue(SaToken::isLogin($pc), 'PC 端 token 应不受影响');
        $this->assertTrue(SaToken::isLogin($pad), '平板端 token 应不受影响');

        reset_satoken_test_config();
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
     * 测试多 token 各自拥有独立的 extra 数据，互不干扰
     */
    public function test_multiple_tokens_each_have_independent_extra()
    {
        set_satoken_test_config(['max_login_count' => 5]);

        $tA = SaToken::login(self::TEST_USER_ID, ['device' => 'phone', 'scopes' => ['read']]);
        $tB = SaToken::login(self::TEST_USER_ID, ['device' => 'pc',    'scopes' => ['read', 'write']]);

        $this->assertEquals(['device' => 'phone', 'scopes' => ['read']], SaToken::getExtra($tA));
        $this->assertEquals(['device' => 'pc',    'scopes' => ['read', 'write']], SaToken::getExtra($tB));

        reset_satoken_test_config();
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
        set_satoken_test_config(['auto_renew' => true, 'timeout' => 60, 'renew_before' => 3600]);
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
        $token        = SaToken::login(self::TEST_USER_ID, ['k' => 'v']);
        $infoBefore   = SaToken::getTokenInfo($token);
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
     * 测试登出功能是否正确移除 token（不影响其他 token）
     */
    public function test_logout_removes_token()
    {
        set_satoken_test_config(['max_login_count' => 5]);

        $t1 = SaToken::login(self::TEST_USER_ID);
        $t2 = SaToken::login(self::TEST_USER_ID);

        $this->assertTrue(SaToken::isLogin($t1));
        $this->assertTrue(SaToken::isLogin($t2));

        $result = SaToken::logout($t1);
        $this->assertTrue($result);

        $tokenKey1 = "satoken:token:$t1";
        $this->assertFalse(Cache::has($tokenKey1), 't1 的 token 信息应被删除');

        $this->assertFalse(SaToken::isLogin($t1), 't1 应已登出');
        $this->assertTrue(SaToken::isLogin($t2), 't2 不应被 t1 的登出影响');

        reset_satoken_test_config();
    }

    /**
     * 测试登录后isLogin应返回true（不同用户互不影响）
     */
    public function test_is_login_after_login()
    {
        $token = SaToken::login(self::TEST_USER_ID);
        $this->assertTrue(SaToken::isLogin($token));

        $anotherToken = SaToken::login(self::ANOTHER_USER_ID);
        $this->assertTrue(SaToken::isLogin($anotherToken));
        // 不同用户的 token 互不影响
        $this->assertTrue(SaToken::isLogin($token));
    }

    /**
     * 测试未登录或无效token时isLogin应返回false
     */
    public function test_is_not_login_for_invalid_token()
    {
        $invalidToken = 'invalid-token-123456';
        $this->assertFalse(SaToken::isLogin($invalidToken));

        $this->assertFalse(SaToken::isLogin(null));
        $this->assertFalse(SaToken::isLogin(''));
    }

    /**
     * 测试登出后isLogin应返回false
     */
    public function test_is_not_login_after_logout()
    {
        $token = SaToken::login(self::TEST_USER_ID);

        $this->assertTrue(SaToken::isLogin($token));

        SaToken::logout($token);

        $this->assertFalse(SaToken::isLogin($token));
    }

    /**
     * 测试getCurrentLoginId应返回正确的登录ID
     */
    public function test_get_current_login_id_returns_correct_id()
    {
        $token = SaToken::login(self::TEST_USER_ID);

        $loginId = SaToken::getCurrentLoginId($token);
        $this->assertEquals(self::TEST_USER_ID, $loginId);

        $anotherToken   = SaToken::login(self::ANOTHER_USER_ID);
        $anotherLoginId = SaToken::getCurrentLoginId($anotherToken);
        $this->assertEquals(self::ANOTHER_USER_ID, $anotherLoginId);
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
        $invalidFormats = [
            'not-a-uuid',
            '12345678',
            '12345678-1234',
            '12345678-1234-1234',
            '12345678-1234-1234-1234',
            '12345678-1234-1234-1234-1234567890abx',
            '1234567-1234-1234-1234-1234567890ab',
            '12345678-1234-6234-1234-1234567890ab',
            '12345678-1234-1234-7234-1234567890ab',
            '12345678-1234-1234-1234-1234567890ab_append',
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
        } catch (Throwable $e) {
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
        $fakeToken = SaToken::createToken();

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
        $token    = SaToken::createToken();
        $tokenKey = "satoken:token:$token";
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
        } catch (Throwable $e) {
            $exception = $e;
        }

        $this->assertNull($exception, 'checkLogin 对多个用户的有效 token 不应抛出异常');
    }

    /**
     * checkLogin：通过校验后应触发滑动续期（剩余时间低于 renew_before 时刷新 TTL）
     */
    public function test_check_login_triggers_renew_when_below_before()
    {
        set_satoken_test_config(['timeout' => 3, 'auto_renew' => true, 'renew_before' => 3]);

        $token        = SaToken::login(self::TEST_USER_ID);
        $expireBefore = SaToken::getTokenExpireTime($token);

        sleep(2); // 剩余时间约 1s，低于 renew_before 3s

        SaToken::checkLogin($token);

        $expireAfter = SaToken::getTokenExpireTime($token);
        $this->assertGreaterThan(
            $expireBefore,
            $expireAfter,
            'checkLogin 通过校验后应触发滑动续期，expire_time 应被刷新'
        );

        reset_satoken_test_config();
    }

    /**
     * checkLogin：与 isLogin 保持一致的成功/失败判定
     */
    public function test_check_login_consistent_with_is_login()
    {
        $validToken   = SaToken::login(self::TEST_USER_ID);
        $invalidToken = SaToken::createToken();

        $this->assertTrue(SaToken::isLogin($validToken));
        $thrown = null;

        try {
            SaToken::checkLogin($validToken);
        } catch (Throwable $e) {
            $thrown = $e;
        }
        $this->assertNull($thrown, 'isLogin 为 true 的 token，checkLogin 也应通过');

        $this->assertFalse(SaToken::isLogin($invalidToken));
        $thrown = null;

        try {
            SaToken::checkLogin($invalidToken);
        } catch (Throwable $e) {
            $thrown = $e;
        }
        $this->assertNotNull($thrown, 'isLogin 为 false 的 token，checkLogin 应抛出异常');
    }

    /**
     * 测试按 loginId 踢出用户：应清除该账号下所有 token（所有端都下线）
     */
    public function test_kickout_by_id_removes_all_tokens_of_user()
    {
        set_satoken_test_config(['max_login_count' => 10]);

        $tA           = SaToken::login(self::TEST_USER_ID);
        $tB           = SaToken::login(self::TEST_USER_ID);
        $tC           = SaToken::login(self::TEST_USER_ID);
        $anotherToken = SaToken::login(self::ANOTHER_USER_ID);

        $this->assertTrue(SaToken::isLogin($tA));
        $this->assertTrue(SaToken::isLogin($tB));
        $this->assertTrue(SaToken::isLogin($tC));
        $this->assertTrue(SaToken::isLogin($anotherToken));

        $result = SaToken::kickout(self::TEST_USER_ID);
        $this->assertTrue($result);

        // 目标用户所有 token 全部失效
        $this->assertFalse(SaToken::isLogin($tA));
        $this->assertFalse(SaToken::isLogin($tB));
        $this->assertFalse(SaToken::isLogin($tC));

        // 其他用户不受影响
        $this->assertTrue(SaToken::isLogin($anotherToken));

        $loginIdKey = 'satoken:loginId:'.self::TEST_USER_ID;
        $this->assertFalse(Cache::has($loginIdKey), 'loginId -> token 列表应被删除');

        reset_satoken_test_config();
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
     * 测试 kickoutByToken：踢出单个 token（不影响同账号其他 token）
     */
    public function test_kickout_by_token_removes_only_one_token_and_keeps_others()
    {
        set_satoken_test_config(['max_login_count' => 10]);

        $t1 = SaToken::login(self::TEST_USER_ID);
        $t2 = SaToken::login(self::TEST_USER_ID);

        $this->assertTrue(SaToken::isLogin($t1));
        $this->assertTrue(SaToken::isLogin($t2));

        $result = SaToken::kickoutByToken($t1);
        $this->assertTrue($result);

        $tokenKey1 = "satoken:token:$t1";
        $this->assertFalse(Cache::has($tokenKey1));

        $this->assertFalse(SaToken::isLogin($t1), '被踢出的 t1 应失效');
        $this->assertTrue(SaToken::isLogin($t2), '同账号的 t2 不应受影响');

        $this->expectException(TokenInvalidException::class);
        SaToken::getCurrentLoginId($t1);

        reset_satoken_test_config();
    }

    /**
     * 测试 kickoutByToken：无效token的处理
     */
    public function test_kickout_by_token_for_invalid_token()
    {
        $invalidToken = 'invalid-token-123456';

        $result = SaToken::kickoutByToken($invalidToken);

        $this->assertFalse($result);
    }

    /**
     * 测试 kickoutByToken：不会影响其他用户的 token
     */
    public function test_kickout_by_token_does_not_affect_other_users()
    {
        $token1 = SaToken::login(self::TEST_USER_ID);
        $token2 = SaToken::login(self::ANOTHER_USER_ID);

        $this->assertTrue(SaToken::isLogin($token1));
        $this->assertTrue(SaToken::isLogin($token2));

        $result = SaToken::kickoutByToken($token1);
        $this->assertTrue($result);

        $this->assertFalse(SaToken::isLogin($token1));
        $this->assertTrue(SaToken::isLogin($token2));
    }

    /**
     * 被顶掉的 token 调用 kickoutByToken 应返回 false
     */
    public function test_kickout_by_token_on_kicked_token_returns_false()
    {
        set_satoken_test_config(['max_login_count' => 1]);

        $t1 = SaToken::login(self::TEST_USER_ID);
        SaToken::login(self::TEST_USER_ID); // t2 顶掉 t1（因为上限为1）

        $this->assertFalse(SaToken::isLogin($t1));
        $this->assertFalse(SaToken::kickoutByToken($t1));

        reset_satoken_test_config();
    }

    /**
     * kickoutByToken 只下线指定 token，同账号其他 token 保持有效
     */
    public function test_kickout_by_token_does_not_affect_other_tokens()
    {
        set_satoken_test_config(['max_login_count' => 3]);

        $t1 = SaToken::login(self::TEST_USER_ID, ['device' => 'A']);
        $t2 = SaToken::login(self::TEST_USER_ID, ['device' => 'B']);
        $t3 = SaToken::login(self::TEST_USER_ID, ['device' => 'C']);

        $this->assertTrue(SaToken::kickoutByToken($t2));

        $this->assertFalse(SaToken::isLogin($t2)); // B 端已下线
        $this->assertTrue(SaToken::isLogin($t1));  // A 端仍在线
        $this->assertTrue(SaToken::isLogin($t3));  // C 端仍在线
        $this->assertEquals(['device' => 'A'], SaToken::getExtra($t1));
        $this->assertEquals(['device' => 'C'], SaToken::getExtra($t3));

        reset_satoken_test_config();
    }

    /**
     * kickoutByToken 后释放名额：新登录不会误删活着的 token（关键回归测试）
     */
    public function test_kickout_by_token_releases_slot_and_new_login_keeps_alive_tokens()
    {
        set_satoken_test_config(['max_login_count' => 2]);

        $t1 = SaToken::login(self::TEST_USER_ID, ['device' => 'phone']);
        $t2 = SaToken::login(self::TEST_USER_ID, ['device' => 'pc']);

        // 此时 max=2，已占满
        $this->assertTrue(SaToken::isLogin($t1));
        $this->assertTrue(SaToken::isLogin($t2));

        // kickoutByToken 掉 t2，释放一个名额
        SaToken::kickoutByToken($t2);
        $this->assertFalse(SaToken::isLogin($t2));

        // 再登录生成 t3，此时应该不影响 t1（之前的 bug 会误把 t1 顶掉）
        $t3 = SaToken::login(self::TEST_USER_ID, ['device' => 'tablet']);
        $this->assertTrue(SaToken::isLogin($t3));

        // 关键断言：t1 必须还活着！
        $this->assertTrue(SaToken::isLogin($t1), 't1 不应因 kickoutByToken 后再登录而被误删');
        $this->assertEquals(['device' => 'phone'], SaToken::getExtra($t1));

        reset_satoken_test_config();
    }

    /**
     * logout 后同样释放名额，不影响同账号其他 token
     */
    public function test_logout_releases_slot_without_affecting_others()
    {
        set_satoken_test_config(['max_login_count' => 2]);

        $t1 = SaToken::login(self::TEST_USER_ID, ['device' => 'A']);
        $t2 = SaToken::login(self::TEST_USER_ID, ['device' => 'B']);

        // 用户自己登出 t1
        SaToken::logout($t1);
        $this->assertFalse(SaToken::isLogin($t1));
        $this->assertTrue(SaToken::isLogin($t2));  // B 端不受影响

        // 再登录生成 t3，t2 必须仍有效
        $t3 = SaToken::login(self::TEST_USER_ID, ['device' => 'C']);
        $this->assertTrue(SaToken::isLogin($t2));
        $this->assertTrue(SaToken::isLogin($t3));

        reset_satoken_test_config();
    }

    /**
     * 测试前的设置
     */
    protected function setUp(): void
    {
        parent::setUp();

        Cache::clear();
    }
}
