<?php

// 在全局命名空间中定义config函数，用于测试环境
if (! function_exists('config')) {
    /**
     * @param  string|null  $name
     * @param  mixed  $default
     * @return mixed
     */
    function config(?string $name = null, $default = null)
    {
        if ($name === 'satoken') {
            global $SATOKEN_TEST_CONFIG;
            if (! is_array($SATOKEN_TEST_CONFIG)) {
                $SATOKEN_TEST_CONFIG = [
                    'token_name' => 'satoken',
                    'timeout' => 7200,
                    'is_concurrent' => true,
                    'max_login_count' => 10,
                    'auto_renew' => true,
                    'renew_threshold' => 0.3,
                ];
            }

            return $SATOKEN_TEST_CONFIG;
        }

        return $default;
    }
}

if (! function_exists('set_satoken_test_config')) {
    /**
     * @param  array<string, mixed>  $merge
     */
    function set_satoken_test_config(array $merge): void
    {
        global $SATOKEN_TEST_CONFIG;
        if (! is_array($SATOKEN_TEST_CONFIG)) {
            $SATOKEN_TEST_CONFIG = [];
        }
        $defaults = [
            'token_name' => 'satoken',
            'timeout' => 7200,
            'is_concurrent' => true,
            'max_login_count' => 10,
            'auto_renew' => true,
            'renew_threshold' => 0.3,
        ];
        $SATOKEN_TEST_CONFIG = array_merge($defaults, $SATOKEN_TEST_CONFIG, $merge);
    }
}

if (! function_exists('reset_satoken_test_config')) {
    function reset_satoken_test_config(): void
    {
        global $SATOKEN_TEST_CONFIG;
        $SATOKEN_TEST_CONFIG = [
            'token_name' => 'satoken',
            'timeout' => 7200,
            'is_concurrent' => true,
            'max_login_count' => 10,
            'auto_renew' => true,
            'renew_threshold' => 0.3,
        ];
    }
}