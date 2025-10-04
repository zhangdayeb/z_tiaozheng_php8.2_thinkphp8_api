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
            
            $params = $this->request->param();
            $log_id = intval($params['log_id'] ?? 0);
            $status = $params['status'] ?? '';  // 'win' 或 'lose'
            
            if (empty($log_id)) {
                return json(['code' => 0, 'msg' => '请选择要修改的记录']);
            }
            
            if (!in_array($status, ['win', 'lose'])) {
                return json(['code' => 0, 'msg' => '状态参数错误']);
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
            
            // 判断当前状态
            $current_status = $money_before > 0 ? 'win' : 'lose';
            
            // 如果状态相同，不需要修改
            if ($current_status == $status) {
                return json(['code' => 0, 'msg' => '状态未改变']);
            }
            
            // 计算新的金额（反转正负）
            $new_money = -$money_before;
            
            // 计算金额差值
            $money_diff = $new_money - $money_before;
            
            // 计算新的余额
            $new_money_after = $money_after_before + $money_diff;
            
            // 开启事务
            Db::startTrans();
            
            // 1. 更新当前游戏记录
            Db::connect('zonghepan')
                ->name('game_user_money_logs')
                ->where('id', $log_id)
                ->update([
                    'money' => $new_money,
                    'money_after' => $new_money_after
                ]);
            
            // 2. 更新后续所有记录的money_after
            $subsequent_logs = Db::connect('zonghepan')
                ->name('game_user_money_logs')
                ->where('member_id', $game_log['member_id'])
                ->where('id', '>', $log_id)
                ->select();
            
            $final_balance = $new_money_after;
            foreach ($subsequent_logs as $log) {
                $updated_balance = $log['money_after'] + $money_diff;
                
                Db::connect('zonghepan')
                    ->name('game_user_money_logs')
                    ->where('id', $log['id'])
                    ->update(['money_after' => $updated_balance]);
                
                $final_balance = $updated_balance;
            }
            
            // 3. 更新用户当前余额
            Db::connect('zonghepan')
                ->name('common_user')
                ->where('id', $game_log['member_id'])
                ->update(['money' => $final_balance]);
            
            // 4. 记录修改日志到本地数据库
            Db::name('change_log')->insert([
                'admin_uid' => self::$user['id'],
                'change_user_name' => $user['name'],
                'money_before' => $money_before,
                'money_end' => $new_money,
                'money_change' => $money_diff,
                'change_log' => '修改记录ID:' . $log_id . ',状态:' . $current_status . '->' . $status,
                'created_at' => date('Y-m-d H:i:s')
            ]);
            
            Db::commit();
            
            LogHelper::info('修改游戏记录状态', [
                'admin_id' => self::$user['id'],
                'log_id' => $log_id,
                'status_change' => $current_status . '->' . $status
            ]);
            
            return json([
                'code' => 1,
                'msg' => '修改成功',
                'data' => [
                    'log_id' => $log_id,
                    'money_before' => $money_before,
                    'money_after' => $new_money
                ]
            ]);
            
        } catch (\Exception $e) {
            Db::rollback();
            LogHelper::error('修改异常', $e);
            return json(['code' => 0, 'msg' => '修改失败']);
        }
    }
}