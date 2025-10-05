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
            $new_money = floatval($params['money'] ?? 0);  // 新的赢钱金额
            
            if (empty($log_id)) {
                return json(['code' => 0, 'msg' => '请选择要修改的记录']);
            }
            
            if ($new_money <= 0) {
                return json(['code' => 0, 'msg' => '金额必须大于0']);
            }
            
            // 获取游戏记录 - 注意表名已经有前缀了
            $game_log = Db::connect('zonghepan')
                ->table('ntp_game_user_money_logs')  // 使用完整表名
                ->where('id', $log_id)
                ->find();
            
            if (empty($game_log)) {
                return json(['code' => 0, 'msg' => '记录不存在']);
            }
            
            // 判断是否为赢钱记录（只有赢钱记录才能修改）
            $current_money = floatval($game_log['money']);
            if ($current_money <= 0) {
                return json(['code' => 0, 'msg' => '只能修改赢钱记录']);
            }
            
            // 如果金额没有变化
            if (abs($new_money - $current_money) < 0.01) {
                return json(['code' => 0, 'msg' => '金额未发生变化']);
            }
            
            // 获取用户信息验证权限
            $user = Db::connect('zonghepan')
                ->table('ntp_common_user')  // 使用完整表名
                ->where('id', $game_log['member_id'])
                ->find();
            
            if (empty($user)) {
                return json(['code' => 0, 'msg' => '用户不存在']);
            }
            
            $allowed_users = explode(',', self::$user['controller_user_names']);
            if (!in_array($user['name'], $allowed_users)) {
                return json(['code' => 0, 'msg' => '无权修改该用户记录']);
            }
            
            // 记录原始数据
            $original_money = $current_money;
            $original_money_before = floatval($game_log['money_before']);
            $original_money_after = floatval($game_log['money_after']);
            
            // 计算金额差值
            $money_diff = $new_money - $original_money;
            
            // 计算新的余额
            $new_money_after = $original_money_after + $money_diff;
            
            // 开启事务
            Db::startTrans();
            
            try {
                // 1. 更新当前游戏记录
                $updateData = [
                    'money' => $new_money,
                    'money_after' => $new_money_after,
                    'remark' => '',  // 清空备注
                    'updated_at' => date('Y-m-d H:i:s')
                ];
                
                Db::connect('zonghepan')
                    ->table('ntp_game_user_money_logs')
                    ->where('id', $log_id)
                    ->update($updateData);
                
                LogHelper::debug('更新当前记录', [
                    'log_id' => $log_id,
                    'original_money' => $original_money,
                    'new_money' => $new_money,
                    'money_diff' => $money_diff
                ]);
                
                // 2. 更新后续所有记录的money_before和money_after
                $subsequent_logs = Db::connect('zonghepan')
                    ->table('ntp_game_user_money_logs')
                    ->where('member_id', $game_log['member_id'])
                    ->where('id', '>', $log_id)
                    ->order('id', 'asc')
                    ->select();
                
                LogHelper::debug('后续记录数量', ['count' => count($subsequent_logs)]);
                
                $final_balance = $new_money_after;
                foreach ($subsequent_logs as $log) {
                    // 更新money_before和money_after
                    $updated_money_before = floatval($log['money_before']) + $money_diff;
                    $updated_money_after = floatval($log['money_after']) + $money_diff;
                    
                    Db::connect('zonghepan')
                        ->table('ntp_game_user_money_logs')
                        ->where('id', $log['id'])
                        ->update([
                            'money_before' => $updated_money_before,
                            'money_after' => $updated_money_after,
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                    
                    $final_balance = $updated_money_after;
                    
                    LogHelper::debug('更新后续记录', [
                        'id' => $log['id'],
                        'old_money_before' => $log['money_before'],
                        'new_money_before' => $updated_money_before,
                        'old_money_after' => $log['money_after'],
                        'new_money_after' => $updated_money_after
                    ]);
                }
                
                // 3. 更新用户当前余额
                Db::connect('zonghepan')
                    ->table('ntp_common_user')
                    ->where('id', $game_log['member_id'])
                    ->update([
                        'money' => $final_balance,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                
                LogHelper::debug('更新用户余额', [
                    'user_id' => $game_log['member_id'],
                    'username' => $user['name'],
                    'old_balance' => $user['money'],
                    'new_balance' => $final_balance
                ]);
                
                // 4. 记录修改日志到本地数据库
                $change_log_data = [
                    'admin_uid' => self::$user['id'],
                    'change_user_name' => $user['name'],
                    'money_before' => $original_money,
                    'money_end' => $new_money,
                    'money_change' => $money_diff,
                    'change_log' => sprintf(
                        '修改赢钱记录ID:%d, 金额:%s->%s, 差值:%s',
                        $log_id,
                        number_format($original_money, 2),
                        number_format($new_money, 2),
                        number_format($money_diff, 2)
                    ),
                    'created_at' => date('Y-m-d H:i:s')
                ];
                
                // 检查本地change_log表是否存在
                $tableExists = Db::query("SHOW TABLES LIKE 'ntp_change_log'");
                if (!empty($tableExists)) {
                    Db::table('ntp_change_log')->insert($change_log_data);
                } else {
                    // 如果表不存在，先创建表
                    $this->createChangeLogTable();
                    Db::table('ntp_change_log')->insert($change_log_data);
                }
                
                // 提交事务
                Db::commit();
                
                LogHelper::info('修改赢钱金额成功', [
                    'admin_id' => self::$user['id'],
                    'admin_username' => self::$user['username'] ?? '',
                    'log_id' => $log_id,
                    'user' => $user['name'],
                    'money_change' => $original_money . '->' . $new_money,
                    'diff' => $money_diff
                ]);
                
                return json([
                    'code' => 1,
                    'msg' => '修改成功',
                    'data' => [
                        'log_id' => $log_id,
                        'money_before' => $original_money,
                        'money_after' => $new_money,
                        'money_diff' => $money_diff,
                        'final_balance' => $final_balance
                    ]
                ]);
                
            } catch (\Exception $e) {
                // 回滚事务
                Db::rollback();
                
                LogHelper::error('修改过程中出错', [
                    'error' => $e->getMessage(),
                    'trace' => $e->getTraceAsString(),
                    'log_id' => $log_id
                ]);
                
                return json(['code' => 0, 'msg' => '修改失败：' . $e->getMessage()]);
            }
            
        } catch (\Exception $e) {
            LogHelper::error('修改异常', [
                'error' => $e->getMessage(),
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);
            
            return json(['code' => 0, 'msg' => '系统异常，请稍后重试']);
        }
    }
    
    /**
     * 创建修改日志表
     */
    private function createChangeLogTable()
    {
        $sql = "CREATE TABLE IF NOT EXISTS `ntp_change_log` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `admin_uid` int(11) NOT NULL COMMENT '管理员ID',
            `change_user_name` varchar(100) NOT NULL COMMENT '被修改的用户名',
            `money_before` decimal(16,2) NOT NULL DEFAULT '0.00' COMMENT '修改前金额',
            `money_end` decimal(16,2) NOT NULL DEFAULT '0.00' COMMENT '修改后金额',
            `money_change` decimal(16,2) NOT NULL DEFAULT '0.00' COMMENT '金额变化值',
            `change_log` text COMMENT '修改说明',
            `created_at` datetime DEFAULT NULL COMMENT '创建时间',
            PRIMARY KEY (`id`),
            KEY `idx_admin_uid` (`admin_uid`),
            KEY `idx_user_name` (`change_user_name`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='金额修改日志表';";
        
        Db::execute($sql);
    }
}