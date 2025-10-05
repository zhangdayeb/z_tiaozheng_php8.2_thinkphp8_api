<?php

namespace app\controller;

use app\controller\common\LogHelper;
use app\BaseController;
use app\model\HomeTokenModel;
use app\model\UserModel;

class Base extends BaseController
{
    public static $user;

    public function initialize()
    {
        $this->user_info();
        parent::initialize();
    }

    public function user_info()
    {
        try {
            // 获取请求路径，用于判断是否需要验证
            $path = $this->request->pathinfo();
            
            // 某些路径不需要验证token（如登录、获取配置）
            $noAuthPaths = [
                'game.Login/login',
                'game.SysConfig/get_all_config',
                'index/index'
            ];
            
            // 如果是不需要验证的路径，直接返回
            if (in_array($path, $noAuthPaths)) {
                self::$user = null;
                return;
            }
            
            // 获取token
            $token = $this->request->header('x-csrf-token');
            
            if (empty($token)) {
                LogHelper::debug('Token不存在，路径：' . $path);
                self::$user = null;
                return;
            }
            
            // 验证token
            $res = HomeTokenModel::auth_token($token);
            if (empty($res)) {
                LogHelper::warning('Token验证失败', [
                    'token' => substr($token, 0, 10) . '...', 
                    'path' => $path
                ]);
                self::$user = null;
                return;
            }
            
            // 查询用户信息
            $user_info = UserModel::page_one($res['user_id']);
            if (empty($user_info)) {
                LogHelper::warning('用户不存在', [
                    'user_id' => $res['user_id'],
                    'path' => $path
                ]);
                self::$user = null;
                return;
            }
            
            // 检查用户状态（如果有status字段）
            if (isset($user_info['status']) && $user_info['status'] != 1) {
                LogHelper::warning('用户状态异常', [
                    'user_id' => $res['user_id'],
                    'status' => $user_info['status'],
                    'path' => $path
                ]);
                self::$user = null;
                return;
            }
            
            // 设置用户信息
            self::$user = $user_info;
            
            LogHelper::debug('用户认证成功', [
                'user_id' => $user_info['id'],
                'username' => $user_info['username'] ?? 'N/A',
                'path' => $path
            ]);
            
        } catch (\Exception $e) {
            // 记录详细的错误信息
            LogHelper::error('用户认证异常：' . $e->getMessage(), [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            self::$user = null;
        }
    }
}