<?php
$botToken = getenv('BOT_TOKEN');
$botUsername = getenv('BOT_USERNAME');
$webhookSecret = getenv('WEBHOOK_SECRET');

if (!$botToken || !$botUsername || !$webhookSecret) {
    http_response_code(500);
    error_log("Missing required environment variables");
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
        file_get_contents(API_URL . 'setWebhook?url=' . urlencode($webhook_url));
        exit;
    }
    http_response_code(401);
    exit;
}

function logError($message) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(ERROR_LOG, "[$timestamp] $message\n", FILE_APPEND);
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
    $context = stream_context_create(['http' => ['ignore_errors' => true]]);
    file_get_contents($url, false, $context);
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
                'earn_log' => [],
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

            $msg = "👋 Welcome!\n\n"
                 . "💰 Earn points using the Earn button\n"
                 . "👥 Invite friends with your referral code\n"
                 . "🏧 Withdraw anytime\n\n"
                 . "Your referral code: <b>{$users[$chat_id]['ref_code']}</b>";
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
                'earn_log' => [],
                'referrals' => 0,
                'ref_code' => substr(md5($chat_id . time()), 0, 8),
                'referred_by' => null
            ];
        }

        switch ($data) {
            case 'earn':
                $time_diff = time() - $users[$chat_id]['last_earn'];
                $today = date('Y-m-d');
                if (!isset($users[$chat_id]['earn_log'][$today])) {
                    $users[$chat_id]['earn_log'][$today] = 0;
                }
                if ($users[$chat_id]['earn_log'][$today] >= 10) {
                    $msg = "⚠️ Daily limit reached!";
                    break;
                }
                if ($time_diff < 60) {
                    $msg = "⏳ Wait " . (60 - $time_diff) . " seconds.";
                    break;
                }
                $adLink = "https://www.profitableratecpm.com/zwkb15jq4?key=e2e955e0e5da5ef9ac896b08cb169010";
                $msg = "🎥 Watch this Ad to earn 10 points!\n\n👉 $adLink\n\nWhen done, tap ✅ Confirm.";
                $keyboard = [[['text' => '✅ Confirm', 'callback_data' => 'confirm_earn']]];
                sendMessage($chat_id, $msg, $keyboard);
                return;

            case 'confirm_earn':
                $time_diff = time() - $users[$chat_id]['last_earn'];
                if ($time_diff < 60) {
                    $msg = "⏳ Wait " . (60 - $time_diff) . " seconds.";
                    break;
                }
                $users[$chat_id]['balance'] += 10;
                $users[$chat_id]['last_earn'] = time();
                $today = date('Y-m-d');
                $users[$chat_id]['earn_log'][$today]++;
                $msg = "✅ You earned 10 points!\nNew balance: {$users[$chat_id]['balance']}";
                break;

            case 'balance':
                $msg = "💳 Balance: {$users[$chat_id]['balance']}\nReferrals: {$users[$chat_id]['referrals']}";
                break;

            case 'leaderboard':
                $leaderboard = [];
                foreach ($users as $id => $user) {
                    $leaderboard[$id] = $user['balance'];
                }
                arsort($leaderboard);
                $top = array_slice($leaderboard, 0, 5, true);
                $msg = "🏆 Top Earners\n";
                $i = 1;
                foreach ($top as $id => $bal) {
                    $msg .= "$i. User $id: $bal points\n";
                    $i++;
                }
                break;

            case 'referrals':
                $msg = "👥 Your code: <b>{$users[$chat_id]['ref_code']}</b>\nTotal referrals: {$users[$chat_id]['referrals']}\n\nInvite:\nhttps://t.me/" . BOT_USERNAME . "?start={$users[$chat_id]['ref_code']}\n\n🎁 50 points per referral!";
                break;

            case 'withdraw':
                $min = 100;
                if ($users[$chat_id]['balance'] < $min) {
                    $msg = "🏧 Minimum $min points.\nBalance: {$users[$chat_id]['balance']}";
                } else {
                    $amount = $users[$chat_id]['balance'];
                    $users[$chat_id]['balance'] = 0;
                    $msg = "🏧 Withdrawal requested: $amount points.";
                }
                break;

            case 'help':
                $msg = "❓ Help\n\n💰 Earn: 10 points every minute\n👥 Refer: 50 points per friend\n🏧 Withdraw: Min 100 points.";
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
?>
