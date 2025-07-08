<?php
$botToken = getenv('BOT_TOKEN');
$botUsername = getenv('BOT_USERNAME');
$webhookSecret = getenv('WEBHOOK_SECRET');

if (!$botToken || !$botUsername || !$webhookSecret) {
    http_response_code(500);
    die("Server configuration error");
}

define('BOT_TOKEN', $botToken);
define('BOT_USERNAME', $botUsername);
define('API_URL', 'https://api.telegram.org/bot' . BOT_TOKEN . '/');
define('USERS_FILE', 'users.json');
define('ERROR_LOG', 'error.log');

if (isset($_GET['set_webhook'])) {
    if ($_GET['set_webhook'] === $webhookSecret) {
        $webhook_url = 'https://' . $_SERVER['HTTP_HOST'] . '/';
        $result = file_get_contents(API_URL . 'setWebhook?url=' . urlencode($webhook_url));
        echo "Webhook set to: $webhook_url<br>Response: " . htmlspecialchars($result);
        exit;
    }
    http_response_code(401);
    exit;
}

function logError($message) {
    file_put_contents(ERROR_LOG, "[" . date('Y-m-d H:i:s') . "] $message\n", FILE_APPEND);
}

function loadUsers() {
    if (!file_exists(USERS_FILE)) {
        file_put_contents(USERS_FILE, json_encode([]));
        chmod(USERS_FILE, 0664);
    }
    $data = file_get_contents(USERS_FILE);
    return json_decode($data, true) ?: [];
}

function saveUsers($users) {
    file_put_contents(USERS_FILE, json_encode($users, JSON_PRETTY_PRINT));
}

function sendMessage($chat_id, $text, $keyboard = null) {
    $params = [
        'chat_id' => $chat_id,
        'text' => $text,
        'parse_mode' => 'HTML'
    ];
    if ($keyboard) {
        $params['reply_markup'] = json_encode(['inline_keyboard' => $keyboard]);
    }
    $url = API_URL . 'sendMessage?' . http_build_query($params);
    file_get_contents($url);
}

function getMainKeyboard() {
    return [
        [['text' => '💰 Earn', 'callback_data' => 'earn'], ['text' => '💳 Balance', 'callback_data' => 'balance']],
        [['text' => '🏆 Leaderboard', 'callback_data' => 'leaderboard'], ['text' => '👥 Referrals', 'callback_data' => 'referrals']],
        [['text' => '🏧 Withdraw', 'callback_data' => 'withdraw'], ['text' => '❓ Help', 'callback_data' => 'help']]
    ];
}

function processUpdate($update) {
    $users = loadUsers();

    if (isset($update['message'])) {
        $chat_id = $update['message']['chat']['id'];
        $text = trim($update['message']['text'] ?? '');

        if (!isset($users[$chat_id])) {
            $users[$chat_id] = [
                'balance' => 0,
                'last_earn' => 0,
                'referrals' => 0,
                'ref_code' => substr(md5($chat_id . time()), 0, 8),
                'referred_by' => null
            ];
        }

        if (strpos($text, '/start') === 0) {
            $ref = explode(' ', $text)[1] ?? null;
            if ($ref && !$users[$chat_id]['referred_by']) {
                foreach ($users as $id => $user) {
                    if ($user['ref_code'] === $ref && $id != $chat_id) {
                        $users[$chat_id]['referred_by'] = $id;
                        $users[$id]['referrals']++;
                        $users[$id]['balance'] += 50;
                        sendMessage($id, "🎉 New referral! +50 points bonus!");
                        break;
                    }
                }
            }

            $msg = "👋 Welcome!\n\n".
                   "💰 Earn points by clicking Earn\n".
                   "👥 Invite friends with your code\n".
                   "🏧 Withdraw anytime\n\n".
                   "Your referral code: <b>{$users[$chat_id]['ref_code']}</b>";

            sendMessage($chat_id, $msg, getMainKeyboard());
        }

    } elseif (isset($update['callback_query'])) {
        $query = $update['callback_query'];
        $chat_id = $query['message']['chat']['id'];
        $data = $query['data'];
        $message_id = $query['message']['message_id'];

        if (!isset($users[$chat_id])) {
            $users[$chat_id] = [
                'balance' => 0,
                'last_earn' => 0,
                'referrals' => 0,
                'ref_code' => substr(md5($chat_id . time()), 0, 8),
                'referred_by' => null
            ];
        }

        switch ($data) {
            case 'earn':
                $time_diff = time() - ($users[$chat_id]['last_earn'] ?? 0);
                if ($time_diff < 60) {
                    $remaining = 60 - $time_diff;
                    $msg = "⏳ Wait $remaining seconds!";
                } else {
                    $earn = 10;
                    $users[$chat_id]['balance'] += $earn;
                    $users[$chat_id]['last_earn'] = time();
                    $msg = "✅ You earned $earn points!\nNew balance: {$users[$chat_id]['balance']}";
                }
                break;

            case 'balance':
                $msg = "💳 Balance: {$users[$chat_id]['balance']} points\nReferrals: {$users[$chat_id]['referrals']}";
                break;

            case 'leaderboard':
                $leaderboard = [];
                foreach ($users as $id => $user) {
                    $leaderboard[$id] = $user['balance'];
                }
                arsort($leaderboard);
                $top = array_slice($leaderboard, 0, 5, true);
                $msg = "🏆 Top Earners\n\n";
                $i = 1;
                foreach ($top as $id => $bal) {
                    $msg .= "$i. User $id: $bal points\n";
                    $i++;
                }
                break;

            case 'referrals':
                $msg = "👥 Referral\nYour code: <b>{$users[$chat_id]['ref_code']}</b>\nTotal referrals: {$users[$chat_id]['referrals']}\n\n".
                       "Invite link:\nhttps://t.me/" . BOT_USERNAME . "?start={$users[$chat_id]['ref_code']}\n\n".
                       "🎁 50 points per referral!";
                break;

            case 'withdraw':
                $min = 100;
                if ($users[$chat_id]['balance'] < $min) {
                    $needed = $min - $users[$chat_id]['balance'];
                    $msg = "🏧 Minimum: $min points\nYou need $needed more!";
                } else {
                    $amount = $users[$chat_id]['balance'];
                    $users[$chat_id]['balance'] = 0;
                    $msg = "🏧 Withdrawal requested!\nAmount: $amount points.\nOur team will process within 24 hours.";
                }
                break;

            case 'help':
                $msg = "❓ Help\n\n".
                       "💰 Earn: 10 points every minute\n".
                       "👥 Refer: 50 points per friend\n".
                       "🏧 Withdraw: Min 100 points\n\n".
                       "Need help? Contact @support";
                break;
        }

        $params = [
            'chat_id' => $chat_id,
            'message_id' => $message_id,
            'text' => $msg,
            'parse_mode' => 'HTML',
            'reply_markup' => json_encode(['inline_keyboard' => getMainKeyboard()])
        ];
        $url = API_URL . 'editMessageText?' . http_build_query($params);
        file_get_contents($url);
    }

    saveUsers($users);
}

$content = file_get_contents("php://input");
$update = json_decode($content, true);

if ($update && (isset($update['message']) || isset($update['callback_query']))) {
    processUpdate($update);
    http_response_code(200);
    exit;
}

echo "Bot is running.";
?>
