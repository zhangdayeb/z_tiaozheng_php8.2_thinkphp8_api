<?php
namespace app\controller\game;

use app\controller\common\LogHelper;
use app\controller\Base;
use think\facade\Db;

class Search extends Base
{
    public function search()
    {
        try {
            // 验证登录
            if (empty(self::$user)) {
                return json(['code' => 0, 'msg' => '请先登录']);
            }
            
            $username = input('post.username', '');
            $start_date = input('post.start_date', '');
            $end_date = input('post.end_date', '');
            $page = input('post.page', 1, 'intval');
            $limit = input('post.limit', 20, 'intval');
            
            if (empty($username)) {
                return json(['code' => 0, 'msg' => '请输入要查询的用户名']);
            }
            
            // 检查权限
            $allowed_users = explode(',', self::$user['controller_user_names']);
            if (!in_array($username, $allowed_users)) {
                return json(['code' => 0, 'msg' => '无权查询该用户']);
            }
            
            // 连接远程数据库查询用户
            $remote_user = Db::connect('zonghepan')
                ->name('common_user')
                ->where('name', $username)
                ->find();
            
            if (empty($remote_user)) {
                return json(['code' => 0, 'msg' => '用户不存在']);
            }
            
            // 构建查询条件
            $where = [
                ['member_id', '=', $remote_user['id']]
            ];
            
            if (!empty($start_date)) {
                $where[] = ['created_at', '>=', $start_date . ' 00:00:00'];
            }
            
            if (!empty($end_date)) {
                $where[] = ['created_at', '<=', $end_date . ' 23:59:59'];
            }
            
            // 查询游戏记录
            $total = Db::connect('zonghepan')
                ->name('game_user_money_logs')
                ->where($where)
                ->count();
            
            $list = Db::connect('zonghepan')
                ->name('game_user_money_logs')
                ->where($where)
                ->order('id desc')
                ->page($page, $limit)
                ->select();
            
            LogHelper::info('查询游戏记录', [
                'admin_id' => self::$user['id'],
                'search_user' => $username,
                'date_range' => $start_date . '~' . $end_date
            ]);
            
            return json([
                'code' => 1,
                'msg' => '查询成功',
                'data' => [
                    'total' => $total,
                    'list' => $list,
                    'user_info' => [
                        'username' => $remote_user['name'],
                        'money' => $remote_user['money']
                    ]
                ]
            ]);
            
        } catch (\Exception $e) {
            LogHelper::error('查询异常', $e);
            return json(['code' => 0, 'msg' => '查询失败']);
        }
    }
}