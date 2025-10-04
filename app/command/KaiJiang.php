<?php
declare (strict_types = 1);

namespace app\command;

use app\controller\common\LogHelper;
use think\console\Command;
use think\console\Input;
use think\console\input\Argument;
use think\console\Output;
use think\facade\Db;
use DateTime;
use DateTimeZone;

class KaiJiang extends Command
{
    protected function configure()
    {
        $this->setName('kaijiang')
            ->setDescription('彩票开奖数据模拟生成命令');
    }

    protected function execute(Input $input, Output $output)
    {
        // 强制设置时区为东八区
        date_default_timezone_set('Asia/Shanghai');
        
        $startTime = microtime(true);
        $output->writeln("开始执行彩票开奖数据生成... [时区: " . date_default_timezone_get() . "]");
        $output->writeln("当前东八区时间: " . date('Y-m-d H:i:s'));
        
        LogHelper::debug('=== 开奖数据生成任务开始 ===', [
            'timezone' => date_default_timezone_get(),
            'current_time' => date('Y-m-d H:i:s')
        ]);
        
        try {
            // 获取所有启用的台桌
            $tables = $this->getActiveTables();
            $output->writeln("找到 " . count($tables) . " 个启用的台桌");
            
            $totalGenerated = 0;
            
            foreach ($tables as $table) {
                $output->writeln("处理台桌: {$table['table_title']} (ID: {$table['id']})");
                
                // 获取游戏类型配置
                $gameType = $this->getGameType($table['game_type_id']);
                if (empty($gameType)) {
                    $output->writeln("  跳过: 游戏类型不存在");
                    continue;
                }
                
                // 生成该台桌的开奖数据
                $generated = $this->generateTableData($table, $gameType, $output);
                $totalGenerated += $generated;
                
                $output->writeln("  生成了 {$generated} 条开奖数据");
            }
            
            $endTime = microtime(true);
            $duration = round($endTime - $startTime, 2);
            
            $output->writeln("任务完成! 总共生成 {$totalGenerated} 条开奖数据，耗时 {$duration} 秒");
            LogHelper::debug('=== 开奖数据生成任务完成 ===', [
                'total_generated' => $totalGenerated,
                'duration_seconds' => $duration
            ]);
            
        } catch (\Exception $e) {
            $output->writeln("任务执行失败: " . $e->getMessage());
            LogHelper::error('开奖数据生成任务失败', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
        }
    }
    
    /**
     * 获取所有启用的台桌
     */
    private function getActiveTables(): array
    {
        return Db::table('ntp_dianji_table')
            ->where('status', 1)
            ->order('id asc')
            ->select()
            ->toArray();
    }
    
    /**
     * 获取游戏类型配置
     */
    private function getGameType($gameTypeId): array
    {
        $gameType = Db::table('ntp_dianji_game_type')
            ->where('id', $gameTypeId)
            ->find();
        
        return $gameType ?: [];
    }
    
    /**
     * 为指定台桌生成开奖数据 - 清晰的跨天逻辑
     */
    private function generateTableData($table, $gameType, $output): int
    {
        // 使用东八区时间
        $currentDateTime = new DateTime('now', new DateTimeZone('Asia/Shanghai'));
        $currentTime = $currentDateTime->getTimestamp();
        $endTime = $currentTime + (2 * 3600); // 2小时后
        
        // 获取循环周期 (投注时间 + 开牌时间)
        $cycleSeconds = $table['countdown_time'] + $table['kaipai_time'];
        
        $batchData = [];
        $generated = 0;
        
        $output->writeln("  东八区当前时间: " . $currentDateTime->format('Y-m-d H:i:s'));
        $output->writeln("  营业时间: {$table['start_time']} - {$table['close_time']}");
        $output->writeln("  周期时长: {$cycleSeconds}秒");
        $output->writeln("  生成到: " . date('Y-m-d H:i:s', $endTime));
        
        // 按日期处理，确保跨天期号从001开始
        $processTimestamp = $currentTime;
        
        while ($processTimestamp <= $endTime) {
            $processDate = date('Y-m-d', $processTimestamp);
            $dateStr = date('Ymd', $processTimestamp);
            
            $output->writeln("  处理日期: {$processDate} ({$dateStr})");
            
            // 创建这一天的营业时间范围
            $dayStartTime = strtotime($processDate . ' ' . $table['start_time']);
            $dayEndTime = strtotime($processDate . ' ' . $table['close_time']);
            
            // 获取这一天已存在的最大期号（每天从001开始）
            $maxExistingQihao = $this->getMaxQihaoNumber($table['id'], $dateStr);
            
            $output->writeln("    营业时间: " . date('H:i:s', $dayStartTime) . " - " . date('H:i:s', $dayEndTime));
            $output->writeln("    已存在最大期号: {$maxExistingQihao}期");
            
            // 计算这一天需要生成到第几期
            $dayProcessStart = max($processTimestamp, $dayStartTime);
            $dayProcessEnd = min($endTime, $dayEndTime);
            
            if ($dayProcessEnd < $dayStartTime) {
                // 这一天还未开始营业，跳到下一天
                $processTimestamp = strtotime($processDate . ' +1 day');
                continue;
            }
            
            if ($dayProcessStart > $dayEndTime) {
                // 这一天已结束营业，跳到下一天
                $processTimestamp = strtotime($processDate . ' +1 day');
                continue;
            }
            
            // 计算当前时间在这一天应该到第几期
            $elapsedInDay = $dayProcessStart - $dayStartTime;
            $currentPeriodInDay = floor($elapsedInDay / $cycleSeconds) + 1;
            
            // 计算生成到这一天的第几期
            $endElapsedInDay = $dayProcessEnd - $dayStartTime;
            $endPeriodInDay = floor($endElapsedInDay / $cycleSeconds) + 1;
            
            $output->writeln("    当前应该到第: {$currentPeriodInDay}期");
            $output->writeln("    生成到第: {$endPeriodInDay}期");
            
            // 确定生成起点：从当前期号开始（如果已存在期号小于当前应该的期号）
            $startPeriod = max($maxExistingQihao + 1, $currentPeriodInDay);
            
            $output->writeln("    开始生成第: {$startPeriod}期");
            
            // 生成这一天的期号数据
            for ($periodNumber = $startPeriod; $periodNumber <= $endPeriodInDay; $periodNumber++) {
                // 生成完整期号（每天从001开始）
                $fullQihao = $dateStr . str_pad((string)$periodNumber, $table['qihao_weishu'], '0', STR_PAD_LEFT);
                
                // 检查是否已存在
                $exists = Db::table('ntp_dianji_lu_zhu')
                    ->where([
                        'table_id' => $table['id'],
                        'qihao_number' => $fullQihao
                    ])
                    ->find();
                
                if ($exists) {
                    continue;
                }
                
                // 计算这期的时间
                $periodStartTime = $dayStartTime + ($periodNumber - 1) * $cycleSeconds;
                $showTimeTimestamp = (int)($periodStartTime + $table['countdown_time']);
                $showTime = date('Y-m-d H:i:s', $showTimeTimestamp);
                
                // 生成开奖结果
                $result = $this->generateResult($gameType);
                
                // 准备插入数据
                $batchData[] = [
                    'status' => 1,
                    'create_time' => date('Y-m-d H:i:s'),
                    'update_time' => date('Y-m-d H:i:s'),
                    'show_time' => $showTime,
                    'table_id' => $table['id'],
                    'game_type' => $table['game_type_id'],
                    'result' => $result,
                    'qihao_number' => $fullQihao,
                    'remark' => '系统自动生成'
                ];
                
                $generated++;
                
                $output->writeln("    生成期号: {$fullQihao}, 开奖时间: {$showTime}");
                
                // 批量插入 (每100条插入一次)
                if (count($batchData) >= 100) {
                    Db::table('ntp_dianji_lu_zhu')->insertAll($batchData);
                    $batchData = [];
                }
            }
            
            // 跳到下一天
            $processTimestamp = strtotime($processDate . ' +1 day');
        }
        
        // 插入剩余数据
        if (!empty($batchData)) {
            Db::table('ntp_dianji_lu_zhu')->insertAll($batchData);
        }
        
        return $generated;
    }
    
    /**
     * 获取指定台桌指定日期的最大期号
     */
    private function getMaxQihaoNumber($tableId, $dateStr): int
    {
        $prefix = $dateStr;
        
        $maxQihao = Db::table('ntp_dianji_lu_zhu')
            ->where('table_id', $tableId)
            ->where('qihao_number', 'like', $prefix . '%')
            ->max('qihao_number');
        
        if (empty($maxQihao)) {
            return 0;
        }
        
        // 确保 $maxQihao 是字符串类型
        $maxQihao = (string)$maxQihao;
        
        // 提取期号部分
        $qihaoStr = substr($maxQihao, strlen($prefix));
        return intval($qihaoStr);
    }
    
    /**
     * 根据游戏类型生成开奖结果
     */
    private function generateResult($gameType): string
    {
        switch ($gameType['run_type']) {
            case '1': // 随机彩
                return $this->generateRandomResult($gameType);
            case '2': // 排列彩
                return $this->generateArrangementResult($gameType);
            case '3': // 挑选彩
                return $this->generateSelectionResult($gameType);
            default:
                throw new \Exception("不支持的彩票类型: " . $gameType['run_type']);
        }
    }
    
    /**
     * 生成随机彩结果
     */
    private function generateRandomResult($gameType): string
    {
        $position = intval($gameType['suiji_position']);
        $rangeStr = $gameType['suiji_range'];
        
        // 解析取值范围
        $range = explode(',', $rangeStr);
        $results = [];
        
        for ($i = 0; $i < $position; $i++) {
            $results[] = $range[array_rand($range)];
        }
        
        return implode(',', $results);
    }
    
    /**
     * 生成排列彩结果
     */
    private function generateArrangementResult($gameType): string
    {
        $rangeStr = $gameType['pailie_range'];
        
        // 解析排列范围
        $range = explode(',', $rangeStr);
        
        // 随机打乱数组
        shuffle($range);
        
        return implode(',', $range);
    }
    
    /**
     * 生成挑选彩结果
     */
    private function generateSelectionResult($gameType): string
    {
        $position = intval($gameType['tiaoxuan_position']);
        $rangeStr = $gameType['tiaoxuan_range'];
        $needTema = intval($gameType['tiaoxuan_tema']);
        
        // 解析挑选范围
        $range = explode(',', $rangeStr);
        
        // 随机挑选不重复的数字
        $selectedKeys = array_rand($range, min($position, count($range)));
        
        // 确保返回数组
        if (!is_array($selectedKeys)) {
            $selectedKeys = [$selectedKeys];
        }
        
        $results = [];
        foreach ($selectedKeys as $key) {
            $results[] = str_pad($range[$key], 2, '0', STR_PAD_LEFT);
        }
        
        // 排序结果 (一般挑选彩都是按大小排序)
        sort($results);
        
        $resultStr = implode(',', $results);
        
        // 如果需要特码
        if ($needTema) {
            // 从剩余数字中随机选择一个作为特码
            $remainingRange = array_diff($range, array_intersect_key($range, array_flip($selectedKeys)));
            if (!empty($remainingRange)) {
                $temaKey = array_rand($remainingRange);
                $tema = str_pad($remainingRange[$temaKey], 2, '0', STR_PAD_LEFT);
                $resultStr .= '|特码:' . $tema;
            }
        }
        
        return $resultStr;
    }
}