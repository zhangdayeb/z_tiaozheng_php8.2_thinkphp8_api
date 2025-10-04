<?php
declare (strict_types = 1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\Output;
use think\facade\Db;
use think\facade\Queue;
use think\facade\Log;
use think\facade\Cache;

class JieSuan extends Command
{
    protected function configure()
    {
        // 指令配置
        $this->setName('jiesuan')
            ->setDescription('监控未结算订单并推送到结算队列');
    }

    protected function execute(Input $input, Output $output)
    {
        // 强制设置东八区时间
        date_default_timezone_set('Asia/Shanghai');
        
        $output->writeln('结算监控进程启动...');
        $output->writeln('当前时间：' . date('Y-m-d H:i:s'));
        
        // 无限循环监控
        while (true) {
            try {
                $this->processUnsettledRecords($output);
            } catch (\Exception $e) {
                // 记录错误日志但不退出进程
                Log::error('结算监控异常：' . $e->getMessage());
                $output->error('处理异常：' . $e->getMessage());
                
                // 出错后等待3秒再继续，避免频繁报错
                sleep(3);
                
                // 尝试重连数据库
                try {
                    Db::connect()->query('SELECT 1');
                } catch (\Exception $dbException) {
                    $output->error('数据库重连中...');
                    sleep(5);
                }
            }
            
            // 每秒执行一次
            sleep(1);
        }
    }

    /**
     * 处理未结算记录
     */
    protected function processUnsettledRecords(Output $output)
    {
        // 获取当前时间
        $currentTime = date('Y-m-d H:i:s');
        
        // 查询未结算的记录，关联露珠表获取开奖时间
        $unsettledRecords = Db::table('ntp_dianji_records')
            ->alias('r')
            ->join('ntp_dianji_lu_zhu l', 'r.qihao_number = l.qihao_number AND r.table_id = l.table_id', 'LEFT')
            ->field('r.id, r.qihao_number, r.table_id, r.user_id, l.show_time')
            ->where('r.close_status', 1)  // 未结算状态
            ->where('l.show_time', '<=', $currentTime)  // 已到开奖时间
            ->where('l.show_time', 'NOT NULL')  // 确保有开奖时间
            ->limit(100)  // 每次最多处理100条，防止内存溢出
            ->select();
        
        if (empty($unsettledRecords)) {
            return;
        }
        
        $output->writeln(sprintf('[%s] 发现 %d 条待结算记录', 
            date('Y-m-d H:i:s'), 
            count($unsettledRecords)
        ));
        
        $redis = Cache::store('redis');
        $pushedCount = 0;
        
        foreach ($unsettledRecords as $record) {
            // 构建Redis缓存key
            $cacheKey = 'jiesuan_pushed:' . $record['id'];
            
            // 检查是否已经推送过（1分钟内）
            if ($redis->get($cacheKey)) {
                continue;  // 已推送过，跳过
            }
            
            try {
                // 推送到结算队列
                Queue::push(
                    'app\job\UserJieSuanJob',
                    ['record_id' => $record['id']],
                    'user_jiesuan'
                );
                
                // 设置Redis缓存，防止1分钟内重复推送
                $redis->setex($cacheKey, 60, 1);
                
                $pushedCount++;
                
                // 记录日志
                Log::info(sprintf('推送结算任务：record_id=%d, qihao=%s, user_id=%d', 
                    $record['id'],
                    $record['qihao_number'],
                    $record['user_id']
                ));
                
            } catch (\Exception $e) {
                Log::error(sprintf('推送结算任务失败：record_id=%d, error=%s', 
                    $record['id'],
                    $e->getMessage()
                ));
            }
        }
        
        if ($pushedCount > 0) {
            $output->writeln(sprintf('[%s] 成功推送 %d 条记录到结算队列', 
                date('Y-m-d H:i:s'), 
                $pushedCount
            ));
        }
    }
}