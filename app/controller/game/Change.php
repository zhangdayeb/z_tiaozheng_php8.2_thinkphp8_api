<?php
namespace app\controller\game;

use app\controller\common\LogHelper;
use app\controller\Base;
use think\facade\Db;

class Change extends Base
{
    public function change()
    {
        try {
            // 验证登录
            if (empty(self::$user)) {
                return json(['code' => 0, 'msg' => '请先登录']);
            }
            
            $log_id = input('post.log_id', 0, 'intval');
            $change_money = input('post.change_money', 0, 'floatval');
            $remark = input('post.remark', '');
            
            if (empty($log_id)) {
                return json(['code' => 0, 'msg' => '请选择要修改的记录']);
            }
            
            // 获取游戏记录
            $game_log = Db::connect('zonghepan')
                ->name('game_user_money_logs')
                ->where('id', $log_id)
                ->find();
            
            if (empty($game_log)) {
                return json(['code' => 0, 'msg' => '记录不存在']);
            }
            
            // 获取用户信息验证权限
            $user = Db::connect('zonghepan')
                ->name('common_user')
                ->where('id', $game_log['member_id'])
                ->find();
            
            $allowed_users = explode(',', self::$user['controller_user_names']);
            if (!in_array($user['name'], $allowed_users)) {
                return json(['code' => 0, 'msg' => '无权修改该用户记录']);
            }
            
            // 记录原始数据
            $money_before = $game_log['money'];
            $money_after_before = $game_log['money_after'];
            
            // 计算新的金额
            $new_money = $money_before + $change_money;
            $new_money_after = $money_after_before + $change_money;
            
            // 开启事务
            Db::startTrans();
            
            // 更新游戏记录
            Db::connect('zonghepan')
                ->name('game_user_money_logs')
                ->where('id', $log_id)
                ->update([
                    'money' => $new_money,
                    'money_after' => $new_money_after,
                    'remark' => $game_log['remark'] . ' [调整:' . $change_money . ']'
                ]);
            
            // 记录修改日志
            Db::name('change_log')->insert([
                'admin_uid' => self::$user['id'],
                'change_user_name' => $user['name'],
                'money_before' => $money_before,
                'money_end' => $new_money,
                'money_change' => $change_money,
                'change_log' => '修改记录ID' . $log_id . ',' . $remark
            ]);
            
            Db::commit();
            
            LogHelper::info('修改游戏记录', [
                'admin_id' => self::$user['id'],
                'log_id' => $log_id,
                'change_money' => $change_money
            ]);
            
            return json([
                'code' => 1,
                'msg' => '修改成功',
                'data' => [
                    'log_id' => $log_id,
                    'money_before' => $money_before,
                    'money_after' => $new_money,
                    'change_money' => $change_money
                ]
            ]);
            
        } catch (\Exception $e) {
            Db::rollback();
            LogHelper::error('修改异常', $e);
            return json(['code' => 0, 'msg' => '修改失败']);
        }
    }
}