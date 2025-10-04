<?php
namespace app\job;

use app\controller\common\LogHelper;
use think\facade\Log;
use think\facade\Db;
use think\facade\Queue;
use think\queue\Job;

/**
 * 彩票用户结算队列任务
 * 处理彩票开奖后的用户投注结算
 */
class UserJieSuanJob
{
    /**
     * 队列任务执行入口
     * @param Job $job 任务对象
     * @param array $data 任务数据
     */
    public function fire(Job $job, $data = null)
    {
        // 强制设置东八区时间
        date_default_timezone_set('Asia/Shanghai');
        
        echo sprintf("[%s] 开始处理结算任务，record_id: %d\n", 
            date('Y-m-d H:i:s'), 
            $data['record_id'] ?? 0
        );
        
        // 记录开始处理日志
        Log::info('开始结算任务', [
            'record_id' => $data['record_id'] ?? null,
            'job_id' => $job->getJobId(),
            'attempts' => $job->attempts()
        ]);
        
        try {
            // 验证数据
            if (empty($data['record_id'])) {
                throw new \Exception('缺少record_id参数');
            }
            
            $recordId = intval($data['record_id']);
            
            // 执行结算
            $this->processSettlement($recordId);
            
            echo sprintf("[%s] 记录 %d 结算处理完成\n", 
                date('Y-m-d H:i:s'), 
                $recordId
            );
            
            Log::info(sprintf('结算任务完成：record_id=%d', $recordId));
            
            // 任务执行成功，删除任务
            $job->delete();
            
        } catch (\Exception $e) {
            // 记录错误日志
            Log::error('结算任务执行失败', [
                'record_id' => $data['record_id'] ?? null,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            
            echo sprintf("[%s] 结算任务失败：%s\n", 
                date('Y-m-d H:i:s'), 
                $e->getMessage()
            );
            
            // 检查重试次数
            if ($job->attempts() > 3) {
                // 超过3次重试，记录失败并删除任务
                Log::error(sprintf('结算任务超过最大重试次数，放弃处理：record_id=%d', 
                    $data['record_id'] ?? 0
                ));
                
                // 可以将状态更新为结算失败
                try {
                    Db::name('dianji_records')
                        ->where('id', $recordId)
                        ->update([
                            'close_status' => 5, // 5表示结算失败（自定义状态）
                            'updated_at' => date('Y-m-d H:i:s')
                        ]);
                } catch (\Exception $updateEx) {
                    Log::error('更新失败状态失败: ' . $updateEx->getMessage());
                }
                
                $job->delete();
            } else {
                // 重新发布任务，10秒后重试
                $job->release(10);
            }
        }
    }
    
    /**
     * 处理结算逻辑
     * @param int $recordId
     * @throws \Exception
     */
    protected function processSettlement($recordId)
    {
        // 开启事务
        Db::startTrans();
        
        try {
            // 1. 获取订单信息
            $record = Db::name('dianji_records')
                ->where('id', $recordId)
                ->where('close_status', 1) // 确保是待结算状态
                ->lock(true) // 加锁防止并发
                ->find();
            
            if (!$record) {
                throw new \Exception(sprintf('订单不存在或已结算：record_id=%d', $recordId));
            }
            
            // 2. 获取赔率信息和结算函数
            $peilv = Db::name('dianji_game_peilv')
                ->where('id', $record['game_peilv_id'])
                ->find();
            
            if (!$peilv) {
                throw new \Exception(sprintf('赔率信息不存在：peilv_id=%d', $record['game_peilv_id']));
            }
            
            // 3. 获取开奖结果
            $luzhu = Db::name('dianji_lu_zhu')
                ->where('qihao_number', $record['qihao_number'])
                ->where('table_id', $record['table_id'])
                ->find();
            
            if (!$luzhu) {
                throw new \Exception(sprintf('开奖结果不存在：qihao=%s, table_id=%s', 
                    $record['qihao_number'], $record['table_id']));
            }
            
            // 4. 执行结算判断
            $gameFunction = $peilv['game_function'];
            $gameCanshu = $peilv['game_canshu'];
            $kaijiangResult = $luzhu['result'];
            
            // 检查函数是否存在
            if (!function_exists($gameFunction)) {
                throw new \Exception(sprintf('结算函数不存在：%s', $gameFunction));
            }
            
            // 调用结算函数判断是否中奖
            $isWin = false;
            if (empty($gameCanshu)) {
                // 部分函数没有参数，使用默认值
                $isWin = $gameFunction($kaijiangResult);
            } else {
                $isWin = $gameFunction($kaijiangResult, $gameCanshu);
            }
            
            // 5. 准备更新数据
            $updateData = [
                'lu_zhu_id' => $luzhu['id'],
                'close_status' => 2, // 已结算
                'result' => $kaijiangResult,
                'game_type' => $luzhu['game_type'],
                'updated_at' => date('Y-m-d H:i:s')
            ];
            
            // 获取赔率值（从记录中读取）
            $gamePeilvValue = floatval($record['game_peilv']);
            $betAmt = floatval($record['bet_amt']);
            
            // 声明钱包通知数据变量
            $walletNotificationData = null;
            
            if ($isWin) {
                // 中奖处理
                $winAmt = bcmul((string)$betAmt, (string)$gamePeilvValue, 2);
                $deltaAmt = bcadd($winAmt, (string)$betAmt, 2); // 奖金 + 本金
                
                $updateData['win_amt'] = $winAmt;
                $updateData['delta_amt'] = $deltaAmt;
                
                // 更新用户余额
                $user = Db::name('common_user')
                    ->where('id', $record['user_id'])
                    ->lock(true)
                    ->find();
                
                if (!$user) {
                    throw new \Exception(sprintf('用户不存在：user_id=%d', $record['user_id']));
                }
                
                $oldBalance = floatval($user['money_balance']);
                $newBalance = bcadd((string)$oldBalance, (string)$deltaAmt, 2);
                
                // 更新用户余额
                Db::name('common_user')
                    ->where('id', $record['user_id'])
                    ->update([
                        'money_balance' => $newBalance,
                        'updated_at' => date('Y-m-d H:i:s')
                    ]);
                
                // 写入资金日志
                Db::name('common_pay_money_log')->insert([
                    'create_time' => date('Y-m-d H:i:s'),
                    'type' => 1, // 收入
                    'status' => 501, // 游戏
                    'money_before' => $oldBalance,
                    'money_end' => $newBalance,
                    'money' => $deltaAmt,
                    'uid' => $record['user_id'],
                    'source_id' => $recordId,
                    'mark' => sprintf('彩票中奖，期号:%s，赔率:%s，中奖金额:%s', 
                        $record['qihao_number'], 
                        $gamePeilvValue,
                        $deltaAmt
                    )
                ]);
                
                // 🔥 调整1：添加type字段 - 准备钱包通知数据（中奖情况）
                $walletNotificationData = [
                    'type' => 'settle',  // 添加type字段，标识为结算类型
                    'userData' => [
                        'id' => $record['user_id'],
                        'user_id' => $record['user_id'],
                        'bet_amt' => $betAmt,
                        'money_balance_add_temp' => $deltaAmt,  // 返还总金额（本金+奖金）
                        'win' => $winAmt  // 净赢金额（不含本金）
                    ],
                    'luzhu_id' => $luzhu['id']
                ];
                
                Log::info(sprintf('用户中奖结算：user_id=%d, record_id=%d, win_amt=%s, delta_amt=%s', 
                    $record['user_id'], $recordId, $winAmt, $deltaAmt));
                
            } else {
                // 未中奖处理
                $updateData['win_amt'] = 0;
                $updateData['delta_amt'] = -$betAmt;
                
                // 🔥 调整2：添加type字段 - 准备钱包通知数据（未中奖情况）
                $walletNotificationData = [
                    'type' => 'settle',  // 添加type字段，标识为结算类型
                    'userData' => [
                        'id' => $record['user_id'],
                        'user_id' => $record['user_id'],
                        'bet_amt' => $betAmt,
                        'money_balance_add_temp' => 0,  // 未中奖返还金额为0
                        'win' => -$betAmt  // 净输金额（负数）
                    ],
                    'luzhu_id' => $luzhu['id']
                ];
                
                // 未中奖不需要更新用户余额（下注时已扣除）
                // 未中奖不需要写入资金日志
                
                Log::info(sprintf('用户未中奖：user_id=%d, record_id=%d, lose_amt=%s', 
                    $record['user_id'], $recordId, $betAmt));
            }
            
            // 6. 更新记录状态
            Db::name('dianji_records')
                ->where('id', $recordId)
                ->update($updateData);
            
            // 提交事务
            Db::commit();
            
            // 事务提交成功后，推送钱包通知
            $this->pushWalletNotification($walletNotificationData, $recordId, $isWin);
            
            // 记录成功日志
            Log::info('结算成功', [
                'record_id' => $recordId,
                'user_id' => $record['user_id'],
                'qihao_number' => $record['qihao_number'],
                'is_win' => $isWin,
                'result' => $kaijiangResult,
                'game_function' => $gameFunction,
                'game_canshu' => $gameCanshu,
                'win_amt' => $updateData['win_amt'] ?? 0,
                'delta_amt' => $updateData['delta_amt'] ?? 0
            ]);
            
        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            
            // 抛出异常让上层处理
            throw $e;
        }
    }
    
    /**
     * 推送钱包通知到队列
     * @param array|null $walletData 钱包通知数据
     * @param int $recordId 记录ID
     * @param bool $isWin 是否中奖
     */
    protected function pushWalletNotification($walletData, $recordId, $isWin)
    {
        try {
            // 检查环境配置是否启用钱包通知
            $zongHePanEnable = env('ZONGHEPAN.enable', false);
            
            // 转换为布尔值（处理字符串 "true"/"false" 的情况）
            if (is_string($zongHePanEnable)) {
                $zongHePanEnable = filter_var($zongHePanEnable, FILTER_VALIDATE_BOOLEAN);
            }
            
            if (!$zongHePanEnable) {
                Log::debug('钱包通知未启用', [
                    'config_value' => env('ZONGHEPAN.enable'),
                    'record_id' => $recordId
                ]);
                return;
            }
            
            if (empty($walletData)) {
                Log::warning('钱包通知数据为空，跳过推送', ['record_id' => $recordId]);
                return;
            }
            
            // 🔥 调整3：修改队列名称 - 推送到钱包通知队列
            Queue::push(
                'app\job\ZongHeMoneyJob',
                $walletData,
                'cp_zonghemoney_log_queue'  // 统一使用 cp_ 前缀的队列名
            );
            
            // 🔥 调整4：修改日志中的队列名称
            Log::info('钱包通知已推送到队列', [
                'record_id' => $recordId,
                'user_id' => $walletData['userData']['user_id'] ?? null,
                'luzhu_id' => $walletData['luzhu_id'] ?? null,
                'is_win' => $isWin,
                'bet_amt' => $walletData['userData']['bet_amt'] ?? 0,
                'win' => $walletData['userData']['win'] ?? 0,
                'queue' => 'cp_zonghemoney_log_queue'  // 统一队列名
            ]);
            
        } catch (\Exception $e) {
            // 钱包通知失败不影响主流程，只记录错误日志
            Log::error('推送钱包通知失败', [
                'record_id' => $recordId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    /**
     * 任务失败处理
     * @param array $data
     */
    public function failed($data)
    {
        // 记录最终失败日志
        Log::error('结算任务最终失败', [
            'record_id' => $data['record_id'] ?? null,
            'time' => date('Y-m-d H:i:s')
        ]);
        
        echo sprintf("[%s] 结算任务最终失败，record_id: %d\n", 
            date('Y-m-d H:i:s'), 
            $data['record_id'] ?? 0
        );
    }
}