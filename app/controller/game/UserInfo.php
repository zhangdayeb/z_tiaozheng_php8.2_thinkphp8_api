<?php
namespace app\controller\game;

use app\controller\common\LogHelper;
use app\controller\Base;

class UserInfo extends Base
{
    /**
     * 获取用户详细信息
     * 
     * @return string JSON响应
     */
    public function get_user_info()
    {
        // 检查用户是否已认证
        if (empty(self::$user)) {
            LogHelper::error('用户未认证或认证失败');
            return show([], config('ToConfig.http_code.error'), '用户认证失败');
        }

        LogHelper::debug('获取用户信息请求', [
            'user_id' => self::$user['id'],
            'user_name' => self::$user['user_name']
        ]);
        
        try {
            // 获取已验证的用户信息
            $userData = self::$user;
            
            // 移除敏感信息
            unset($userData['pwd']);
            
            LogHelper::debug('用户信息获取成功', [
                'user_id' => $userData['id'],
                'balance' => $userData['money_balance']
            ]);
            
            return show($userData, 1, '获取用户信息成功');
            
        } catch (\Exception $e) {
            LogHelper::error('获取用户信息失败', [
                'error' => $e->getMessage(),
                'user_id' => self::$user['id'] ?? 'unknown'
            ]);
            return show([], config('ToConfig.http_code.error'), '获取用户信息失败');
        }
    }
}