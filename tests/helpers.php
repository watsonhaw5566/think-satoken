<?php

use think\facade\Config;

if (! function_exists('get_satoken_default_config')) {
    /**
     * @return array<string, mixed>
     */
    function get_satoken_default_config(): array
    {
        return [
            'token_name' => 'satoken',
            'timeout' => 7200,
            'is_concurrent' => true,
            'max_login_count' => 10,
            'auto_renew' => true,
            'renew_threshold' => 0.3,
        ];
    }
}

if (! function_exists('set_satoken_test_config')) {
    /**
     * @param  array<string, mixed>  $merge
     */
    function set_satoken_test_config(array $merge): void
    {
        $current = Config::get('satoken');
        if (! is_array($current)) {
            $current = [];
        }
        Config::set(array_merge(get_satoken_default_config(), $current, $merge), 'satoken');
    }
}

if (! function_exists('reset_satoken_test_config')) {
    function reset_satoken_test_config(): void
    {
        Config::set(get_satoken_default_config(), 'satoken');
    }
}