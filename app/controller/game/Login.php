<?php
namespace app\controller\game;

use app\controller\common\LogHelper;
use think\facade\Db;
use app\BaseController;

class Login extends BaseController
{
    public function login()
    {
        try {
            $params = $this->request->param();
            $username = $params['username'] ?? '';
            $password = $params['password'] ?? '';
            
            if (empty($username) || empty($password)) {
                return json(['code' => 0, 'msg' => '用户名或密码不能为空']);
            }
            
            // 查询用户（注意pwd字段）
            $user = Db::name('common_user')
                ->where('username', $username)
                ->where('pwd', $password)
                ->find();
            
            if (empty($user)) {
                LogHelper::warning('登录失败', ['username' => $username]);
                return json(['code' => 0, 'msg' => '用户名或密码错误']);
            }
            
            // 检查有效期
            if (strtotime($user['expired_at']) < time()) {
                return json(['code' => 0, 'msg' => '账号已过期']);
            }
            
            // 生成token
            $token = md5($username . time() . rand(1000, 9999));
            
            // 获取客户端IP
            $ip = $this->request->ip();
            
            // 保存token到数据库
            $tokenData = [
                'token' => $token,
                'user_id' => $user['id'],
                'create_time' => date('Y-m-d H:i:s'),
                'ip' => $ip
            ];
            
            Db::name('common_home_token')->insert($tokenData);
            
            LogHelper::info('登录成功', [
                'username' => $username, 
                'user_id' => $user['id'],
                'ip' => $ip
            ]);
            
            return json([
                'code' => 1,
                'msg' => '登录成功',
                'data' => [
                    'token' => $token,
                    'username' => $user['username'],
                    'controller_users' => $user['controller_user_names']
                ]
            ]);
            
        } catch (\Exception $e) {
            LogHelper::error('登录异常：' . $e->getMessage(), [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);
            return json(['code' => 0, 'msg' => '系统异常，请稍后重试']);
        }
    }
}