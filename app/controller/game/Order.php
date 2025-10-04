<?php
namespace app\controller\game;

use app\controller\common\LogHelper;
use app\controller\Base;
use think\facade\Db;
use think\facade\Queue;
use app\job\ZongHeMoneyJob;  // 🔥 新增：引入钱包队列任务类

/**
 * 彩票游戏订单控制器
 * 处理用户投注相关业务
 */
class Order extends Base
{
    /**
     * 用户下注接口
     * 
     * @return string JSON响应
     */
    public function order_add()
    {
        LogHelper::debug('=== 用户下注请求开始 ===', [
            'user_id' => self::$user['id'],
            'user_name' => self::$user['user_name']
        ]);
        
        // 获取请求参数
        $table_id = $this->request->param('table_id', 0);
        $qihao_number = $this->request->param('qihao_number', '');
        $bet_data_json = $this->request->param('bet_data', '');
        
        // ========================================
        // 基础参数验证
        // ========================================
        if (empty($table_id) || !is_numeric($table_id)) {
            LogHelper::warning('台桌ID参数无效', ['table_id' => $table_id]);
            return show([], config('ToConfig.http_code.error'), '台桌ID必填且必须为数字');
        }
        
        if (empty($qihao_number)) {
            LogHelper::warning('期号参数无效', ['qihao_number' => $qihao_number]);
            return show([], config('ToConfig.http_code.error'), '期号必填');
        }
        
        if (empty($bet_data_json)) {
            LogHelper::warning('投注数据为空');
            return show([], config('ToConfig.http_code.error'), '投注数据不能为空');
        }
        
        // ========================================
        // 智能处理投注数据格式
        // ========================================
        if (is_array($bet_data_json)) {
            // 已经是数组格式（JSON请求体自动解析）
            $bet_data = $bet_data_json;
            LogHelper::debug('投注数据已是数组格式', ['type' => 'array']);
        } else if (is_string($bet_data_json) && !empty($bet_data_json)) {
            // 字符串格式，需要JSON解码
            $bet_data = json_decode($bet_data_json, true);
            LogHelper::debug('投注数据从JSON字符串解析', ['type' => 'string']);
        } else {
            $bet_data = null;
        }
        
        // 验证解析结果
        if (!is_array($bet_data) || empty($bet_data)) {
            LogHelper::warning('投注数据格式无效', [
                'bet_data_type' => gettype($bet_data_json),
                'bet_data_raw' => $bet_data_json
            ]);
            return show([], config('ToConfig.http_code.error'), '投注数据格式无效');
        }
        
        // ========================================
        // 验证每项投注数据的完整性
        // ========================================
        foreach ($bet_data as $index => $bet) {
            if (!isset($bet['peilv_id']) || !isset($bet['bet_amt'])) {
                LogHelper::warning('投注数据格式不完整', [
                    'index' => $index, 
                    'bet' => $bet
                ]);
                return show([], config('ToConfig.http_code.error'), "第{$index}项投注数据格式不完整");
            }
            
            if (!is_numeric($bet['peilv_id']) || !is_numeric($bet['bet_amt']) || $bet['bet_amt'] <= 0) {
                LogHelper::warning('投注数据数值无效', [
                    'index' => $index, 
                    'bet' => $bet
                ]);
                return show([], config('ToConfig.http_code.error'), "第{$index}项投注数据数值无效");
            }
        }
        
        $table_id = intval($table_id);
        LogHelper::debug('参数验证通过', [
            'table_id' => $table_id,
            'qihao_number' => $qihao_number,
            'bet_count' => count($bet_data)
        ]);
        
        try {
            // 验证台桌状态和投注时间窗口
            $table_check = $this->validateTableStatus($table_id);
            if ($table_check['error']) {
                return show([], config('ToConfig.http_code.error'), $table_check['message']);
            }
            $table_info = $table_check['data'];
            
            // 获取赔率配置
            $peilv_config = $this->getPeilvConfig($table_info);
            
            // 验证投注数据和计算总金额
            $validation_result = $this->validateBetData($bet_data, $peilv_config);
            if ($validation_result['error']) {
                return show([], config('ToConfig.http_code.error'), $validation_result['message']);
            }
            
            $total_amount = $validation_result['total_amount'];
            
            // 验证用户余额
            if (self::$user['money_balance'] < $total_amount) {
                LogHelper::warning('用户余额不足', [
                    'user_balance' => self::$user['money_balance'],
                    'required_amount' => $total_amount
                ]);
                return show([], config('ToConfig.http_code.error'), 
                    "余额不足，当前余额：" . self::$user['money_balance'] . "，需要：{$total_amount}");
            }
            
            // 🔥 修改：调用新的带钱包队列的投注事务处理方法
            $result = $this->processBetTransactionWithWallet(
                $table_id, 
                $qihao_number, 
                $bet_data, 
                $peilv_config, 
                $total_amount,
                $table_info  // 传递台桌信息用于获取游戏类型
            );
            
            if ($result['success']) {
                LogHelper::debug('=== 用户下注完成 ===', [
                    'user_id' => self::$user['id'],
                    'total_amount' => $total_amount,
                    'bet_count' => count($bet_data)
                ]);
                return show([], 200, '投注成功');
            } else {
                return show([], config('ToConfig.http_code.error'), $result['message']);
            }
            
        } catch (\Exception $e) {
            LogHelper::error('用户下注失败', [
                'user_id' => self::$user['id'],
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return show([], config('ToConfig.http_code.error'), '投注失败，请稍后重试');
        }
    }
    
    /**
     * 验证台桌状态和投注时间窗口
     * 
     * @param int $table_id 台桌ID
     * @return array
     */
    private function validateTableStatus($table_id)
    {
        // 查询台桌信息
        $table_info = Db::table('ntp_dianji_table')
            ->where('id', $table_id)
            ->find();
        
        if (empty($table_info)) {
            LogHelper::warning('台桌不存在', ['table_id' => $table_id]);
            return ['error' => true, 'message' => '台桌不存在'];
        }
        
        // 验证台桌状态
        if ($table_info['status'] != 1) {
            LogHelper::warning('台桌已停用', [
                'table_id' => $table_id, 
                'status' => $table_info['status']
            ]);
            return ['error' => true, 'message' => '台桌已停用'];
        }
        
        // ========================================
        // 时间计算逻辑（参考TableInfo控制器）
        // ========================================
        date_default_timezone_set('Asia/Shanghai');  // 强制使用东八区时间
        $now = time();
        
        // 解析营业开始时间
        $startTimeParts = explode(':', $table_info['start_time']);
        if (count($startTimeParts) < 2) {
            LogHelper::warning('开始时间格式无效', ['start_time' => $table_info['start_time']]);
            return ['error' => true, 'message' => '台桌配置错误'];
        }
        
        $startHour = intval($startTimeParts[0]);
        $startMinute = intval($startTimeParts[1]);
        $startSecond = isset($startTimeParts[2]) ? intval($startTimeParts[2]) : 0;
        
        // 计算今天的营业开始时间
        $todayStart = strtotime(date('Y-m-d') . " {$startHour}:{$startMinute}:{$startSecond}");
        
        // 如果还没到营业时间，允许投注第一期
        if ($now < $todayStart) {
            LogHelper::debug('营业时间未到，允许投注第一期', [
                'table_id' => $table_id,
                'now' => date('Y-m-d H:i:s', $now),
                'start_time' => date('Y-m-d H:i:s', $todayStart)
            ]);
            return ['error' => false, 'data' => $table_info];
        }
        
        // 计算从营业开始到现在的总秒数
        $elapsedSeconds = $now - $todayStart;
        
        // 计算周期时长
        $cycleDuration = $table_info['countdown_time'] + $table_info['kaipai_time'];
        
        // 计算当前周期内的位置
        $positionInCycle = $elapsedSeconds % $cycleDuration;
        
        // 计算当前倒计时
        $currentCountdown = $cycleDuration - $positionInCycle;
        
        // 判断游戏状态
        if ($currentCountdown < $table_info['kaipai_time']) {
            LogHelper::warning('当前处于开牌阶段，禁止投注', [
                'table_id' => $table_id,
                'current_countdown' => $currentCountdown,
                'kaipai_time' => $table_info['kaipai_time'],
                'position_in_cycle' => $positionInCycle
            ]);
            return ['error' => true, 'message' => '当前处于开牌阶段，禁止投注'];
        }
        
        LogHelper::debug('台桌状态验证通过', [
            'table_id' => $table_id,
            'elapsed_seconds' => $elapsedSeconds,
            'position_in_cycle' => $positionInCycle,
            'current_countdown' => $currentCountdown,
            'cycle_duration' => $cycleDuration
        ]);
        
        return ['error' => false, 'data' => $table_info];
    }
    
    /**
     * 获取赔率配置（含个性化配置）
     * 
     * @param array $table_info 台桌信息
     * @return array
     */
    private function getPeilvConfig($table_info)
    {
        // 查询基础赔率配置
        $peilv_list = Db::table('ntp_dianji_game_peilv')
            ->where('game_type_id', $table_info['game_type_id'])
            ->select()
            ->toArray();
        
        // 应用个性化配置
        if (!empty($table_info['peilv_config'])) {
            $peilv_list = $this->applyCustomConfig($peilv_list, $table_info['peilv_config']);
        }
        
        // 转换为以ID为键的映射
        $peilv_config = [];
        foreach ($peilv_list as $item) {
            $peilv_config[$item['id']] = $item;
        }
        
        LogHelper::debug('赔率配置获取完成', [
            'peilv_count' => count($peilv_config),
            'has_custom_config' => !empty($table_info['peilv_config'])
        ]);
        
        return $peilv_config;
    }
    
    /**
     * 应用台桌个性化配置
     * 
     * @param array $peilv_list 基础赔率列表
     * @param string $custom_config 个性化配置JSON
     * @return array
     */
    private function applyCustomConfig($peilv_list, $custom_config)
    {
        try {
            $config_data = json_decode($custom_config, true);
            if (!is_array($config_data)) {
                return $peilv_list;
            }
            
            // 构建配置映射
            $config_map = [];
            foreach ($config_data as $config) {
                if (isset($config['peilv_id'])) {
                    $config_map[$config['peilv_id']] = $config;
                }
            }
            
            // 应用个性化配置
            foreach ($peilv_list as &$peilv_item) {
                $peilv_id = $peilv_item['id'];
                
                if (isset($config_map[$peilv_id])) {
                    $custom = $config_map[$peilv_id];
                    
                    if (isset($custom['peilv'])) {
                        $peilv_item['peilv'] = $custom['peilv'];
                    }
                    if (isset($custom['xianhong_min'])) {
                        $peilv_item['xian_hong_min'] = $custom['xianhong_min'];
                    }
                    if (isset($custom['xianhong_max'])) {
                        $peilv_item['xian_hong_max'] = $custom['xianhong_max'];
                    }
                }
            }
            
            return $peilv_list;
            
        } catch (\Exception $e) {
            LogHelper::error('应用个性化配置失败', ['error' => $e->getMessage()]);
            return $peilv_list;
        }
    }
    
    /**
     * 验证投注数据和限红规则
     * 
     * @param array $bet_data 投注数据
     * @param array $peilv_config 赔率配置映射
     * @return array
     */
    private function validateBetData($bet_data, $peilv_config)
    {
        $total_amount = 0;
        
        foreach ($bet_data as $index => $bet) {
            $peilv_id = intval($bet['peilv_id']);
            $bet_amt = floatval($bet['bet_amt']);
            
            // 检查赔率ID是否存在
            if (!isset($peilv_config[$peilv_id])) {
                LogHelper::warning('赔率ID不存在', ['peilv_id' => $peilv_id]);
                return ['error' => true, 'message' => "投注选项ID {$peilv_id} 不存在"];
            }
            
            $config = $peilv_config[$peilv_id];
            
            // 检查限红规则 - 最小投注额
            if ($bet_amt < $config['xian_hong_min']) {
                LogHelper::warning('投注金额低于最小限红', [
                    'peilv_id' => $peilv_id,
                    'bet_amt' => $bet_amt,
                    'min_limit' => $config['xian_hong_min']
                ]);
                return ['error' => true, 'message' => 
                    "{$config['game_tip_name']} 最小投注金额为 {$config['xian_hong_min']}"];
            }
            
            // 检查限红规则 - 最大投注额
            if ($bet_amt > $config['xian_hong_max']) {
                LogHelper::warning('投注金额超过最大限红', [
                    'peilv_id' => $peilv_id,
                    'bet_amt' => $bet_amt,
                    'max_limit' => $config['xian_hong_max']
                ]);
                return ['error' => true, 'message' => 
                    "{$config['game_tip_name']} 最大投注金额为 {$config['xian_hong_max']}"];
            }
            
            $total_amount += $bet_amt;
        }
        
        LogHelper::debug('投注数据验证通过', [
            'total_amount' => $total_amount,
            'bet_count' => count($bet_data)
        ]);
        
        return ['error' => false, 'total_amount' => $total_amount];
    }
    
    /**
     * 🔥 新增方法：处理投注事务（集成钱包队列版本）
     * 
     * @param int $table_id 台桌ID
     * @param string $qihao_number 期号
     * @param array $bet_data 投注数据
     * @param array $peilv_config 赔率配置
     * @param float $total_amount 总投注金额
     * @param array $table_info 台桌信息
     * @return array
     */
    private function processBetTransactionWithWallet($table_id, $qihao_number, $bet_data, $peilv_config, $total_amount, $table_info)
    {
        // 开启事务
        Db::startTrans();
        
        try {
            $user_id = self::$user['id'];
            $current_time = date('Y-m-d H:i:s');
            
            // ========================================
            // 1. 验证并扣减用户余额
            // ========================================
            $before_balance = Db::table('ntp_common_user')
                ->where('id', $user_id)
                ->value('money_balance');
                
            if ($before_balance < $total_amount) {
                throw new \Exception('余额不足');
            }
            
            // 扣减余额（带条件检查）
            $user_update = Db::table('ntp_common_user')
                ->where('id', $user_id)
                ->where('money_balance', '>=', $total_amount)
                ->dec('money_balance', $total_amount)
                ->update();
            
            if (!$user_update) {
                throw new \Exception('扣减用户余额失败，余额不足');
            }
            
            // 计算扣减后的余额
            $updated_balance = $before_balance - $total_amount;
            
            // ========================================
            // 2. 批量插入投注记录
            // ========================================
            $bet_records = [];
            foreach ($bet_data as $bet) {
                $peilv_id = intval($bet['peilv_id']);
                $bet_amt = floatval($bet['bet_amt']);
                $config = $peilv_config[$peilv_id];
                
                $bet_records[] = [
                    'user_id' => $user_id,
                    'table_id' => $table_id,
                    'qihao_number' => $qihao_number,
                    'game_peilv_id' => $peilv_id,
                    'game_peilv' => $config['peilv'],
                    'bet_amt' => $bet_amt,
                    'before_amt' => $before_balance,
                    'end_amt' => $updated_balance,
                    'detail' => "投注：{$config['game_tip_name']}，金额：{$bet_amt}",
                    'close_status' => 1,  // 待开奖
                    'created_at' => $current_time,
                    'updated_at' => $current_time
                ];
            }
            
            $bet_insert = Db::table('ntp_dianji_records')->insertAll($bet_records);
            if (!$bet_insert) {
                throw new \Exception('插入投注记录失败');
            }
            
            // ========================================
            // 3. 插入资金日志
            // ========================================
            $money_log = [
                'create_time' => $current_time,
                'type' => 2,  // 支出
                'status' => 501,  // 游戏投注
                'money_before' => $before_balance,
                'money_end' => $updated_balance,
                'money' => -$total_amount,
                'uid' => $user_id,
                'source_id' => $table_id,
                'mark' => "彩票投注，期号：{$qihao_number}，投注金额：{$total_amount}"
            ];
            
            $log_insert = Db::table('ntp_common_pay_money_log')->insert($money_log);
            if (!$log_insert) {
                throw new \Exception('插入资金日志失败');
            }
            
            // ========================================
            // 🔥 4. 推送钱包下注扣款队列（新增功能）
            // ========================================
            try {
                LogHelper::info('准备推送彩票钱包下注队列', [
                    'user_id' => $user_id,
                    'qihao_number' => $qihao_number,
                    'total_amount' => $total_amount
                ]);
                
                // 构建队列数据
                $queueData = [
                    'type' => 'bet',  // 标识为下注类型
                    'user_id' => $user_id,
                    'user_name' => self::$user['user_name'],
                    'table_id' => $table_id,
                    'qihao_number' => $qihao_number,
                    'total_amount' => $total_amount,
                    'game_type' => $table_info['game_type_id'],  // 游戏类型ID
                    'bet_count' => count($bet_records),  // 投注项数量
                    'is_modify' => false  // 彩票没有修改下注功能
                ];
                
                // 推送到队列（立即执行）
                Queue::push(
                    ZongHeMoneyJob::class, 
                    $queueData, 
                    'cp_zonghemoney_log_queue'  // 彩票专用队列
                );
                
                LogHelper::info('彩票钱包下注队列推送成功', [
                    'queue_name' => 'cp_zonghemoney_log_queue',
                    'queue_data' => $queueData
                ]);
                
            } catch (\Exception $queueException) {
                // 队列推送失败不影响主流程，只记录错误
                LogHelper::error('彩票钱包下注队列推送失败', [
                    'error' => $queueException->getMessage(),
                    'user_id' => $user_id,
                    'qihao_number' => $qihao_number
                ]);
                // 不抛出异常，让投注继续成功
            }
            
            // ========================================
            // 5. 提交事务
            // ========================================
            Db::commit();
            
            LogHelper::info('投注事务处理成功（含钱包队列）', [
                'user_id' => $user_id,
                'total_amount' => $total_amount,
                'bet_count' => count($bet_records),
                'updated_balance' => $updated_balance,
                'wallet_queue_pushed' => true
            ]);
            
            return ['success' => true];
            
        } catch (\Exception $e) {
            // 回滚事务
            Db::rollback();
            
            LogHelper::error('投注事务处理失败', [
                'user_id' => $user_id,
                'error' => $e->getMessage()
            ]);
            
            return ['success' => false, 'message' => '投注处理失败：' . $e->getMessage()];
        }
    }
    
}