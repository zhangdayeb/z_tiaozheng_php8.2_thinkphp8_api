<?php
namespace app\controller\game;

use app\controller\common\LogHelper;
use app\controller\Base;
use think\facade\Db;

class TableInfo extends Base
{
    /**
     * 获取台桌信息和投注选项
     * 
     * @return string JSON响应
     */
    public function table_info()
    {
        LogHelper::debug('获取台桌信息请求');
        
        // 获取参数
        $table_id = $this->request->param('table_id', 0);
        
        // 参数验证
        if (empty($table_id) || !is_numeric($table_id)) {
            LogHelper::warning('台桌ID参数无效', ['table_id' => $table_id]);
            return show([], config('ToConfig.http_code.error'), '台桌ID必填且必须为数字');
        }
        
        $table_id = intval($table_id);
        
        LogHelper::debug('查询台桌信息', ['table_id' => $table_id]);
        
        try {
            // 查询台桌基础信息
            $tableInfo = Db::table('ntp_dianji_table')
                ->where('id', $table_id)
                ->find();
            
            if (empty($tableInfo)) {
                LogHelper::warning('台桌不存在或已停用', ['table_id' => $table_id]);
                return show([], config('ToConfig.http_code.error'), '台桌不存在或已停用');
            }
            
            LogHelper::debug('台桌基础信息查询成功', [
                'table_id' => $table_id,
                'game_type_id' => $tableInfo['game_type_id'],
                'has_custom_config' => !empty($tableInfo['peilv_config'])
            ]);
            
            // 查询游戏类型完整信息
            $gameTypeInfo = Db::table('ntp_dianji_game_type')
                ->where('id', $tableInfo['game_type_id'])
                ->find();
            
            // 构建游戏类型信息对象
            if (empty($gameTypeInfo)) {
                LogHelper::warning('游戏类型信息不存在', ['game_type_id' => $tableInfo['game_type_id']]);
                $gameTypeInfo = null;
            }
            
            // 计算当前期号和游戏状态信息 - 修改：通过引用传递
            $countdownInfo = $this->calculateCurrentQihao($tableInfo);
            
            // 将所有信息添加到返回数据中
            $tableInfo['game_type_info'] = $gameTypeInfo;
            $tableInfo['current_qihao'] = $countdownInfo['current_qihao'];
            $tableInfo['current_full_cycle_countdown'] = $countdownInfo['current_full_cycle_countdown'];
            $tableInfo['current_display_countdown'] = $countdownInfo['current_display_countdown'];
            $tableInfo['current_game_status'] = $countdownInfo['current_game_status'];
            
            LogHelper::debug('台桌信息获取成功', [
                'table_id' => $table_id,
                'game_type_name' => $gameTypeInfo ? $gameTypeInfo['type_name'] : 'Unknown',
                'run_type' => $gameTypeInfo ? $gameTypeInfo['run_type'] : 'Unknown',
                'current_qihao' => $countdownInfo['current_qihao'],
                'current_game_status' => $countdownInfo['current_game_status'],
                'current_display_countdown' => $countdownInfo['current_display_countdown']
            ]);
            
            return show($tableInfo, 1, '获取台桌信息成功');
            
        } catch (\Exception $e) {
            LogHelper::error('获取台桌信息失败', [
                'table_id' => $table_id,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return show([], config('ToConfig.http_code.error'), '获取台桌信息失败');
        }
    }
    
    /**
     * 计算当前期号和游戏状态 - 修改：返回完整的状态信息数组
     */
    private function calculateCurrentQihao($tableInfo): array
    {
        // 强制使用东八区时间
        date_default_timezone_set('Asia/Shanghai');
        $now = time();
        $today = date('Ymd', $now);
        
        // 解析营业开始时间
        $startTimeParts = explode(':', $tableInfo['start_time']);
        if (count($startTimeParts) < 2) {
            return [
                'current_qihao' => $today . '001',
                'current_full_cycle_countdown' => 0,
                'current_display_countdown' => 0,
                'current_game_status' => 'betting'
            ];
        }
        
        $startHour = intval($startTimeParts[0]);
        $startMinute = intval($startTimeParts[1]);
        $startSecond = isset($startTimeParts[2]) ? intval($startTimeParts[2]) : 0;
        
        // 计算今天的营业开始时间
        $todayStart = strtotime(date('Y-m-d') . " {$startHour}:{$startMinute}:{$startSecond}");
        
        // 如果还没到营业时间
        if ($now < $todayStart) {
            return [
                'current_qihao' => $today . '001',
                'current_full_cycle_countdown' => 0,
                'current_display_countdown' => 0,
                'current_game_status' => 'betting'
            ];
        }
        
        // 计算从营业开始到现在的总秒数
        $elapsedSeconds = $now - $todayStart;
        
        // 计算周期时长
        $cycleDuration = $tableInfo['countdown_time'] + $tableInfo['kaipai_time'];
        
        // 计算已完成的周期数
        $completedCycles = floor($elapsedSeconds / $cycleDuration);
        
        // 当前期号 = 已完成周期数 + 1
        $currentPeriod = $completedCycles + 1;
        
        // 计算当前周期内的位置（用于倒计时）
        $positionInCycle = $elapsedSeconds % $cycleDuration;
        
        // 计算当前应该的倒计时
        $currentCountdown = $cycleDuration - $positionInCycle;
        
        // ===== 修改部分开始 =====
        // 计算显示倒计时（可以为负数）
        $displayCountdown = $currentCountdown - $tableInfo['kaipai_time'];
        
        // 判断游戏状态（保持原有逻辑）
        if ($currentCountdown < $tableInfo['kaipai_time']) {
            $gameStatus = 'drawing';
        } else {
            $gameStatus = 'betting';
        }
        // ===== 修改部分结束 =====
        
        // 格式化期号（补0）
        $qihaoWeishu = $tableInfo['qihao_weishu'] ?? 3;
        $paddedPeriod = str_pad((string)$currentPeriod, $qihaoWeishu, '0', STR_PAD_LEFT);
        $finalQihao = $today . $paddedPeriod;
        
        LogHelper::debug('期号和倒计时计算完成', [
            'table_id' => $tableInfo['id'],
            'elapsed_seconds' => $elapsedSeconds,
            'cycle_duration' => $cycleDuration,
            'position_in_cycle' => $positionInCycle,
            'completed_cycles' => $completedCycles,
            'current_period' => $currentPeriod,
            'current_countdown' => $currentCountdown,
            'display_countdown' => $displayCountdown,
            'game_status' => $gameStatus,
            'final_qihao' => $finalQihao
        ]);
        
        return [
            'current_qihao' => $finalQihao,
            'current_full_cycle_countdown' => $currentCountdown,
            'current_display_countdown' => $displayCountdown,
            'current_game_status' => $gameStatus
        ];
    }
}