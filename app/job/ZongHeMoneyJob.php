<?php
namespace app\job;

use app\business\RequestUrl;
use app\business\Curl;
use app\controller\common\LogHelper;
use think\facade\Db;
use think\queue\Job;

/**
 * 彩票钱包通知队列
 * 处理下注扣款和结算通知
 * 
 * Class ZongHeMoneyJob
 * @package app\job
 */
class ZongHeMoneyJob
{
    /**
     * 队列任务执行入口
     * 
     * @param Job $job 队列任务对象
     * @param array|null $data 任务数据
     * @return bool
     */
    public function fire(Job $job, $data = null)
    {
        // 根据type判断操作类型
        $type = $data['type'] ?? 'settle';
        
        LogHelper::info('=== 彩票钱包通知队列开始 ===', [
            'type' => $type,
            'attempt' => $job->attempts(),
            'max_attempts' => 3,
            'queue_name' => 'cp_zonghemoney_log_queue',
            'job_id' => $job->getJobId()
        ]);
        
        LogHelper::info('钱包通知任务数据', $data);

        $taskInfo = $data;

        // 根据类型分发处理
        if ($type === 'bet') {
            // 处理下注扣款
            $isJobDone = $this->processBet($data);
        } else {
            // 处理结算通知
            $isJobDone = $this->processWalletNotification($data);
        }

        if ($isJobDone) {
            if ($type === 'bet') {
                LogHelper::info('彩票钱包下注扣款成功', [
                    'user_id' => $data['user_id'] ?? 'unknown',
                    'qihao_number' => $data['qihao_number'] ?? 'unknown',
                    'amount' => $data['total_amount'] ?? 0
                ]);
            } else {
                LogHelper::info('彩票钱包结算通知成功', [
                    'user_id' => $data['userData']['id'] ?? $data['userData']['user_id'] ?? 'unknown',
                    'luzhu_id' => $data['luzhu_id'] ?? 'unknown'
                ]);
            }
            $job->delete();
            return true;
        }

        // 检查重试次数
        if ($job->attempts() > 3) {
            LogHelper::error('彩票钱包通知失败 - 超过最大重试次数', [
                'type' => $type,
                'data' => $taskInfo,
                'attempts' => $job->attempts(),
                'final_failure' => true
            ]);

            // 记录最终失败的通知
            $this->recordFailedNotification($taskInfo);

            $job->delete();
            return true;
        }

        LogHelper::warning('彩票钱包通知失败 - 将重试', [
            'type' => $type,
            'attempt' => $job->attempts()
        ]);

        // 返回 false 进行重试
        return false;
    }

    /**
     * 处理下注扣款
     * 
     * @param array $data 队列数据
     * @return bool
     */
    private function processBet($data): bool
    {
        try {
            LogHelper::info('处理彩票下注扣款通知', [
                'user_id' => $data['user_id'],
                'user_name' => $data['user_name'],
                'amount' => $data['total_amount'],
                'table_id' => $data['table_id'],
                'qihao_number' => $data['qihao_number'],
                'game_type' => $data['game_type'],
                'bet_count' => $data['bet_count'] ?? 0
            ]);

            // 验证必要参数
            $userName = $data['user_name'];
            
            if (!$userName || !isset($data['total_amount'])) {
                LogHelper::error('下注参数缺失', $data);
                return true; // 参数错误不重试
            }

            // 如果金额为0，直接成功
            if ($data['total_amount'] == 0) {
                LogHelper::info('下注金额为0，无需扣款');
                return true;
            }

            // 构建URL
            $url = env('zonghepan.game_url', '0.0.0.0') . RequestUrl::bet();
            
            // 生成唯一的下注交易ID
            $transactionId = sprintf(
                'CP_BET_%s_U%d_T%d_Q%s',
                date('YmdHis'),
                $data['user_id'],
                $data['table_id'],
                $data['qihao_number']
            );
            
            LogHelper::info('生成彩票下注betId', [
                'bet_id' => $transactionId,
                'user_id' => $data['user_id'],
                'table_id' => $data['table_id'],
                'qihao_number' => $data['qihao_number']
            ]);
            
            // 构建下注扣款参数
            $params = [
                'user_name' => $userName,
                'betId' => $transactionId,
                'externalTransactionId' => 'CP_BET_TXN_' . $transactionId,
                'amount' => floatval($data['total_amount']),
                'gameCode' => 'XG_caipiao',
                'roundId' => $data['qihao_number'],  // 使用期号作为roundId
                'betTime' => intval(time() * 1000),
                'tableId' => $data['table_id'],
                'gameType' => $data['game_type'] ?? 0,
                'betCount' => $data['bet_count'] ?? 0
            ];

            LogHelper::info('彩票钱包下注请求', [
                'url' => $url,
                'params' => $params
            ]);
            
            // 调用钱包API
            $response = Curl::post($url, $params, []);
            
            LogHelper::info('彩票钱包下注响应', ['response' => $response]);
            
            // 处理响应
            if (is_array($response) && isset($response['code']) && $response['code'] == 200) {
                LogHelper::info('彩票钱包下注扣款API调用成功', [
                    'user_name' => $userName,
                    'amount' => $data['total_amount'],
                    'qihao_number' => $data['qihao_number'],
                    'transaction_id' => $transactionId
                ]);
                return true;
            }

            // API返回错误
            $errorMsg = $response['msg'] ?? $response['message'] ?? '未知错误';
            $errorCode = $response['code'] ?? 'unknown';

            LogHelper::warning('彩票钱包下注API返回错误', [
                'user_name' => $userName,
                'error_code' => $errorCode,
                'error_msg' => $errorMsg,
                'response' => $response
            ]);
            
            // 所有错误都重试
            return false;

        } catch (\Exception $e) {
            LogHelper::error('彩票下注扣款异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);
            return false; // 异常需要重试
        }
    }

    /**
     * 处理钱包结算通知
     * 
     * @param array $data 队列数据
     * @return bool 是否成功
     */
    private function processWalletNotification($data): bool
    {
        try {
            LogHelper::info('开始处理彩票钱包结算通知', [
                'luzhu_id' => $data['luzhu_id'] ?? 'unknown',
                'user_id' => $data['userData']['id'] ?? 'unknown'
            ]);

            // 验证必要数据
            if (!$this->validateQueueData($data)) {
                LogHelper::error('队列数据验证失败', $data);
                return true; // 数据错误不重试
            }

            // 从队列数据中提取参数
            $userData = $data['userData'] ?? [];
            $luzhu_id = $data['luzhu_id'] ?? 0;

            if (empty($userData) || $luzhu_id <= 0) {
                LogHelper::error('关键参数缺失', [
                    'userData_empty' => empty($userData),
                    'luzhu_id' => $luzhu_id
                ]);
                return true; // 参数错误不重试
            }

            // 调用钱包结算函数逻辑
            return $this->executeWalletSettlement($userData, $luzhu_id);

        } catch (\Exception $e) {
            LogHelper::error('彩票钱包结算通知异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'data' => $data
            ]);
            return false; // 异常情况需要重试
        }
    }

    /**
     * 验证队列数据完整性
     * 
     * @param array $data 队列数据
     * @return bool
     */
    private function validateQueueData($data): bool
    {
        $requiredFields = ['userData', 'luzhu_id'];

        foreach ($requiredFields as $field) {
            if (!isset($data[$field])) {
                LogHelper::warning("缺少必要字段: {$field}", $data);
                return false;
            }
        }

        return true;
    }

    /**
     * 执行钱包结算逻辑
     * 
     * @param array $userData 用户结算数据
     * @param int $luzhuId 露珠ID
     * @return bool
     */
    private function executeWalletSettlement($userData, $luzhuId): bool
    {
        try {
            // 从 userData 中获取用户ID
            $userId = $userData['id'] ?? $userData['user_id'] ?? null;
            
            if (!$userId) {
                LogHelper::warning('用户ID不存在', ['userData' => $userData]);
                return true; // 数据错误不重试
            }
            
            // 查询用户信息
            $userInfo = Db::table('ntp_common_user')
                ->where('id', $userId)
                ->field('user_name')
                ->find();
            
            if (empty($userInfo)) {
                LogHelper::warning('用户信息不存在', ['user_id' => $userId]);
                return true; // 用户不存在不重试
            }
            
            // 构建URL
            $url = env('zonghepan.game_url', '0.0.0.0') . RequestUrl::bet_result();
            
            LogHelper::info('准备调用彩票钱包结算', [
                'url' => $url,
                'user' => $userInfo['user_name'],
                'luzhu_id' => $luzhuId,
                'user_id' => $userId
            ]);
            
            // 计算金额
            $betAmount = floatval($userData['bet_amt'] ?? 0);
            $winAmount = floatval($userData['money_balance_add_temp'] ?? 0);
            $winLoss = floatval($userData['win'] ?? 0);
            
            // 彩票没有和局，简化判断逻辑
            LogHelper::info('彩票结算金额计算', [
                'user_id' => $userId,
                'bet_amount' => $betAmount,
                'win_amount' => $winAmount,
                'win_loss' => $winLoss
            ]);
            
            // 根据输赢情况设置参数
            if ($winLoss > 0) {
                // 赢钱情况
                $finalWinAmount = $betAmount + $winLoss;  // 返还本金 + 净赢金额
                $finalWinLoss = $winLoss;                 // 净赢金额
                $resultType = 'WIN';
                
                LogHelper::info('彩票玩家赢钱结算', [
                    'user_id' => $userId,
                    'bet_amount' => $betAmount,
                    'win_amount' => $finalWinAmount,
                    'win_loss' => $finalWinLoss,
                    'result_type' => $resultType
                ]);
            } else {
                // 输钱情况（包括 winLoss = 0 的情况）
                $finalWinAmount = 0;                      // 输钱时 winAmount 为 0
                $finalWinLoss = $winLoss;                 // 净输金额（负数或0）
                $resultType = 'LOSE';
                
                LogHelper::info('彩票玩家输钱结算', [
                    'user_id' => $userId,
                    'bet_amount' => $betAmount,
                    'win_amount' => $finalWinAmount,
                    'win_loss' => $finalWinLoss,
                    'result_type' => $resultType
                ]);
            }
            
            // 生成结算专用ID
            $settlementId = sprintf(
                'CP_SETTLE_%s_L%d_U%d',
                date('YmdHis'),
                $luzhuId,
                $userId
            );
            
            LogHelper::info('生成彩票结算betId', [
                'settlement_id' => $settlementId,
                'luzhu_id' => $luzhuId,
                'user_id' => $userId
            ]);
            
            // 准备参数
            $params = [
                'user_name' => $userInfo['user_name'],
                'betId' => $settlementId,
                'roundId' => (string)$luzhuId,
                'externalTransactionId' => 'CP_SETTLE_TXN_' . $settlementId,
                'betAmount' => $betAmount,
                'winAmount' => $finalWinAmount,
                'effectiveTurnover' => $betAmount,
                'winLoss' => $finalWinLoss,
                'jackpotAmount' => 0,
                'resultType' => $resultType,
                'isFreespin' => 0,
                'isEndRound' => 1,
                'betTime' => intval((time() - 60) * 1000),
                'settledTime' => intval(time() * 1000),
                'gameCode' => 'XG_caipiao'
            ];
            
            LogHelper::info('彩票钱包结算请求参数', [
                'user' => $userInfo['user_name'],
                'settlement_id' => $settlementId,
                'params' => $params
            ]);
            
            // 调用钱包API
            $response = Curl::post($url, $params, []);
            
            LogHelper::info('彩票钱包结算响应', [
                'user' => $userInfo['user_name'],
                'luzhu_id' => $luzhuId,
                'settlement_id' => $settlementId,
                'response' => $response
            ]);
            
            // 处理API响应
            return $this->handleAPIResponse($response, $userInfo, $luzhuId);
            
        } catch (\Exception $e) {
            LogHelper::error('executeWalletSettlement异常', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => $userId ?? 'unknown',
                'luzhu_id' => $luzhuId
            ]);
            return false; // 异常需要重试
        }
    }

    /**
     * 处理API响应
     * 
     * @param mixed $response API响应
     * @param array $userInfo 用户信息
     * @param int $luzhuId 露珠ID
     * @return bool
     */
    private function handleAPIResponse($response, $userInfo, $luzhuId): bool
    {
        if (!is_array($response)) {
            LogHelper::warning('彩票钱包API返回格式错误', [
                'user' => $userInfo['user_name'],
                'luzhu_id' => $luzhuId,
                'response_type' => gettype($response),
                'response' => $response
            ]);
            return false;
        }

        if (isset($response['code']) && $response['code'] == 200) {
            LogHelper::info('彩票钱包API调用成功', [
                'user' => $userInfo['user_name'],
                'luzhu_id' => $luzhuId,
                'response' => $response
            ]);
            return true;
        }

        // API返回错误
        $errorMsg = $response['msg'] ?? $response['message'] ?? '未知错误';
        $errorCode = $response['code'] ?? 'unknown';

        LogHelper::warning('彩票钱包API返回错误', [
            'user' => $userInfo['user_name'],
            'luzhu_id' => $luzhuId,
            'error_code' => $errorCode,
            'error_msg' => $errorMsg,
            'response' => $response
        ]);

        // 所有错误都重试
        LogHelper::info('错误类型需要重试', [
            'error_code' => $errorCode,
            'error_msg' => $errorMsg
        ]);
        return false; // 重试
    }

    /**
     * 记录最终失败的通知
     * 可以用于后续手动补发或监控
     * 
     * @param array $data 失败的任务数据
     */
    private function recordFailedNotification($data): void
    {
        try {
            $type = $data['type'] ?? 'settle';
            
            // 记录到特殊日志
            LogHelper::error('彩票钱包通知最终失败 - 需要人工处理', [
                'type' => $type,
                'game' => 'caipiao',
                'user_id' => $type === 'bet' 
                    ? ($data['user_id'] ?? 'unknown')
                    : ($data['userData']['id'] ?? $data['userData']['user_id'] ?? 'unknown'),
                'luzhu_id' => $data['luzhu_id'] ?? 'unknown',
                'qihao_number' => $data['qihao_number'] ?? 'unknown',
                'data' => $data,
                'timestamp' => date('Y-m-d H:i:s'),
                'action_required' => 'MANUAL_RETRY'
            ]);

            // 可选：写入数据库失败记录表
            // Db::table('wallet_failed_notifications')->insert([
            //     'type' => $type,
            //     'game' => 'caipiao',
            //     'data' => json_encode($data),
            //     'created_at' => date('Y-m-d H:i:s')
            // ]);

        } catch (\Exception $e) {
            LogHelper::error('记录失败通知异常', [
                'error' => $e->getMessage()
            ]);
        }
    }
}