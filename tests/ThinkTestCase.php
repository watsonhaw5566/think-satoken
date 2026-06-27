<?php

namespace satoken\tests;

use PHPUnit\Framework\TestCase as BaseTestCase;
use think\App;
use think\Config;
use think\Container;
use think\facade\Cache;

/**
 * ThinkPHP测试用例基类
 */
class ThinkTestCase extends BaseTestCase
{
    /**
     * @var App 应用实例
     */
    protected $app;

    /**
     * 测试前的设置
     */
    protected function setUp(): void
    {
        parent::setUp();

        // 初始化应用
        $this->prepareApp();
    }

    /**
     * 准备应用实例
     */
    protected function prepareApp(): void
    {
        // 创建应用实例
        $this->app = new App(__DIR__.'/../');

        // 设置容器实例
        Container::setInstance($this->app);

        // 配置测试环境
        // 使用正确的方式获取配置实例
        $config = $this->app->make(Config::class);
        $config->set([
            'app_debug' => true,
            'app_trace' => false,
            // 配置缓存驱动，使用file缓存用于测试
            'cache' => [
                'default' => 'file',
                'stores' => [
                    'file' => [
                        'type' => 'file',
                        'path' => __DIR__.'/../runtime/cache/',
                        'expire' => 0,
                        'prefix' => '',
                    ],
                ],
            ],
            // 初始化 satoken 配置
            'satoken' => [
                'token_name' => 'satoken',
                'timeout' => 7200,
                'is_concurrent' => true,
                'max_login_count' => 10,
                'auto_renew' => true,
                'renew_threshold' => 0.3,
            ],
        ]);

        $this->app->instance('config', $config);
    }

    /**
     * 测试后的清理
     */
    protected function tearDown(): void
    {
        parent::tearDown();

        // 清除缓存
        Cache::clear();
    }
}