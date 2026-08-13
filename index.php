<?php
/**
 * 节假日查询系统
 * API: ?action=check&date=YYYY-MM-DD 返回 {"code":0|1}
 * API: ?action=update POST 请求体 {"url":"..."} 解析并更新节假日
 * 兼容 PHP 8.0+
 */

// ========== 全局错误 Handler，防止白屏和 API 静默失败 ==========
$isApiRequest = isset($_GET['action']) && $_GET['action'] !== '';
set_error_handler(function($errno, $errstr, $errfile, $errline) use ($isApiRequest) {
    if ($isApiRequest) {
        if (!headers_sent()) {
            http_response_code(500);
            header('Content-Type: application/json; charset=utf-8');
        }
        echo json_encode(['error' => "PHP错误: {$errstr} (文件 {$errfile} 行 {$errline})"], JSON_UNESCAPED_UNICODE);
    } else {
        if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
        echo "<h2>页面出错</h2>";
        echo "<p><strong>错误：</strong>" . htmlspecialchars($errstr) . "</p>";
        echo "<p><strong>文件：</strong>" . htmlspecialchars($errfile) . " (行 {$errline})</p>";
        echo "<p>请访问 <a href='?action=diag'>诊断页面</a> 检查服务器环境。</p>";
    }
    exit;
});
register_shutdown_function(function() use ($isApiRequest) {
    $error = error_get_last();
    if ($error && in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR])) {
        if ($isApiRequest) {
            if (!headers_sent()) {
                http_response_code(500);
                header('Content-Type: application/json; charset=utf-8');
            }
            echo json_encode(['error' => "致命错误: {$error['message']} (文件 {$error['file']} 行 {$error['line']})"], JSON_UNESCAPED_UNICODE);
        } else {
            if (!headers_sent()) header('Content-Type: text/html; charset=utf-8');
            echo "<h2>致命错误</h2>";
            echo "<p><strong>错误：</strong>" . htmlspecialchars($error['message']) . "</p>";
            echo "<p><strong>文件：</strong>" . htmlspecialchars($error['file']) . " (行 {$error['line']})</p>";
            echo "<p>请访问 <a href='?action=diag'>诊断页面</a> 检查服务器环境。</p>";
        }
    }
});

// 调试模式：访问 ?action=diag 查看服务器环境与诊断信息
if (isset($_GET['action']) && $_GET['action'] === 'diag') {
    header('Content-Type: text/html; charset=utf-8');
    $issues = [];
    if (version_compare(PHP_VERSION, '7.1.0', '<')) $issues[] = "PHP 版本过低（当前 " . PHP_VERSION . "，需要 7.1+）";
    if (!function_exists('curl_init')) $issues[] = "未启用 cURL 扩展（抓取功能将回退到 file_get_contents）";
    if (!function_exists('json_encode')) $issues[] = "未启用 JSON 扩展（必需）";
    if (!is_writable(__DIR__)) $issues[] = "目录不可写（holidays.json 无法保存，请设置权限 755）";
    if (file_exists(__DIR__ . '/holidays.json') && !is_writable(__DIR__ . '/holidays.json')) $issues[] = "holidays.json 不可写（请设置权限 644 或 666）";
    echo "<h2>服务器诊断</h2>";
    echo "<p>PHP 版本：" . PHP_VERSION . "</p>";
    echo "<p>cURL 扩展：" . (function_exists('curl_init') ? '已启用' : '未启用') . "</p>";
    echo "<p>目录可写：" . (is_writable(__DIR__) ? '是' : '否') . "</p>";
    echo "<p>holidays.json 存在：" . (file_exists(__DIR__ . '/holidays.json') ? '是' : '否') . "</p>";
    echo "<p>holidays.json 可写：" . (file_exists(__DIR__ . '/holidays.json') ? (is_writable(__DIR__ . '/holidays.json') ? '是' : '否') : 'N/A') . "</p>";
    echo "<h3>问题列表</h3>";
    if (empty($issues)) {
        echo "<p style='color:green;'>没有发现问题，配置正常。</p>";
    } else {
        foreach ($issues as $i) echo "<p style='color:red;'>- " . htmlspecialchars($i) . "</p>";
    }
    exit;
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$action = $_GET['action'] ?? '';

// ========== API: 检查日期 ==========
if ($action === 'check') {
    $rawDate = isset($_GET['date']) ? $_GET['date'] : '';
    if (!preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $rawDate, $m)) {
        http_response_code(400);
        echo json_encode(['error' => '日期格式错误，请使用 YYYY-MM-DD 格式'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    // 统一补零为 YYYY-MM-DD
    $date = sprintf('%04d-%02d-%02d', intval($m[1]), intval($m[2]), intval($m[3]));
    if (!checkdate($m[2], $m[3], $m[1])) {
        http_response_code(400);
        echo json_encode(['error' => '日期不存在'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $data = loadHolidayData();
    $user = isset($_GET['user']) ? trim($_GET['user']) : '';
    if ($user === '') $user = null;  // 不带 user 时只查公共节假日，向后兼容
    $code = isWorkingDay($date, $data, $user) ? 0 : 1;
    
    // 精简模式：只返回 0 或 1
    if (isset($_GET['simple']) && ($_GET['simple'] === '1' || $_GET['simple'] === 'true')) {
        header('Content-Type: text/plain; charset=utf-8');
        echo $code;
        exit;
    }
    
    $info = getDateInfo($date, $data, $user);
    
    echo json_encode([
        'code' => $code,
        'date' => $date,
        'info' => $info,
        'user' => $user
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== API: 更新节假日 ==========
if ($action === 'update') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => '请使用 POST 方法'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    $url = $input['url'] ?? '';
    $content = $input['content'] ?? '';
    
    // 支持两种模式：URL 抓取 或 直接粘贴网页内容
    if (empty($url) && empty($content)) {
        http_response_code(400);
        echo json_encode(['error' => '请提供网页地址或直接粘贴网页内容'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $result = null;
    $sourceLabel = '';
    
    if (!empty($content)) {
        // 直接粘贴内容模式
        $result = parseHolidayContent($content);
        $sourceLabel = '手动粘贴内容';
    } else {
        // URL 抓取模式
        $result = parseHolidayPage($url);
        $sourceLabel = $url;
    }
    
    if (isset($result['error'])) {
        http_response_code(400);
        echo json_encode($result, JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    // 保存到 JSON 文件，并清理旧数据（只保留最近 2 年）
    $data = loadHolidayData();
    $data[$result['year']] = [
        'holidays' => $result['holidays'],
        'workdays' => $result['workdays'],
        'holiday_names' => $result['holiday_names'],
        'updated_at' => date('c'),
        'source_url' => $sourceLabel
    ];
    
    // 只保留最近 2 年数据，删除更早的年份
    $allYears = [];
    foreach ($data as $k => $v) {
        if (preg_match('/^\d{4}$/', $k)) {
            $allYears[] = $k;
        }
    }
    rsort($allYears);
    $keepYears = array_slice($allYears, 0, 2);
    foreach ($data as $y => $v) {
        if (preg_match('/^\d{4}$/', $y) && !in_array($y, $keepYears)) {
            unset($data[$y]);
        }
    }
    
    saveHolidayData($data);
    
    echo json_encode([
        'success' => true,
        'year' => $result['year'],
        'holiday_count' => count($result['holidays']),
        'workday_count' => count($result['workdays']),
        'holiday_names' => $result['holiday_names'],
        'keep_years' => $keepYears,
        'message' => "成功解析 {$result['year']} 年节假日安排，共 " . count($result['holidays']) . " 天假期，" . count($result['workdays']) . " 天调休上班。当前保留年份：" . implode('、', $keepYears)
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== API: 获取节假日列表（指定年份）或全部年份列表 ==========
if ($action === 'list') {
    $year = $_GET['year'] ?? '';
    $data = loadHolidayData();
    
    if ($year === '') {
        // 返回所有可用年份（排除非年份键如 annual_leave）
        $years = [];
        foreach ($data as $k => $v) {
            if (preg_match('/^\d{4}$/', $k)) {
                $years[] = $k;
            }
        }
        rsort($years);
        echo json_encode(['years' => $years], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    if (!isset($data[$year])) {
        http_response_code(404);
        echo json_encode(['error' => "暂无 {$year} 年节假日数据"], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    echo json_encode($data[$year], JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== API: 删除用户（彻底清除用户及其年假） ==========
if ($action === 'user_delete') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => '请使用 POST 方法'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true);
    $user = isset($input['user']) ? trim($input['user']) : '';
    if ($user === '') {
        http_response_code(400);
        echo json_encode(['error' => '请提供 user 用户名'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $data = loadHolidayData();
    if (isset($data['annual_leave'][$user])) {
        unset($data['annual_leave'][$user]);
        saveHolidayData($data);
        echo json_encode(['success' => true, 'user' => $user, 'message' => "用户 {$user} 已彻底删除"], JSON_UNESCAPED_UNICODE);
    } else {
        echo json_encode(['success' => true, 'user' => $user, 'message' => "用户 {$user} 不存在，无需删除"], JSON_UNESCAPED_UNICODE);
    }
    exit;
}

// ========== API: 用户列表 ==========
if ($action === 'users_list') {
    $data = loadHolidayData();
    $users = $data['annual_leave'] ?? [];
    $result = [];
    foreach ($users as $u => $dates) {
        $result[] = ['user' => $u, 'count' => count($dates)];
    }
    echo json_encode(['users' => $result], JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== API: 年假管理 - 获取列表 ==========
if ($action === 'annual_list') {
    $data = loadHolidayData();
    $user = isset($_GET['user']) ? trim($_GET['user']) : '';
    
    if ($user === '') {
        // 返回所有用户及其年假
        $allLeave = $data['annual_leave'] ?? [];
        foreach ($allLeave as $u => &$dates) {
            sort($dates);
        }
        unset($dates);
        echo json_encode(['annual_leave' => $allLeave], JSON_UNESCAPED_UNICODE);
        exit;
    }
    
    $userLeave = getUserAnnualLeave($data, $user);
    sort($userLeave);
    echo json_encode(['user' => $user, 'annual_leave' => $userLeave], JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== API: 年假管理 - 添加 ==========
if ($action === 'annual_add') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => '请使用 POST 方法'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true);
    $user = isset($input['user']) ? trim($input['user']) : '';
    $newDates = $input['dates'] ?? [];
    
    if ($user === '') {
        http_response_code(400);
        echo json_encode(['error' => '请提供 user 用户名，格式：{"user": "zhangsan", "dates": ["2026-08-14"]}'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!is_array($newDates) || empty($newDates)) {
        http_response_code(400);
        echo json_encode(['error' => '请提供日期数组，格式：{"user": "zhangsan", "dates": ["2026-08-14", "2026-08-15"]}'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $validDates = [];
    foreach ($newDates as $d) {
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $d, $m)) {
            $formatted = sprintf('%04d-%02d-%02d', intval($m[1]), intval($m[2]), intval($m[3]));
            if (checkdate($m[2], $m[3], $m[1])) {
                $validDates[] = $formatted;
            }
        }
    }
    if (empty($validDates)) {
        http_response_code(400);
        echo json_encode(['error' => '未提供有效日期'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $data = loadHolidayData();
    if (!isset($data['annual_leave']) || !is_array($data['annual_leave'])) {
        $data['annual_leave'] = [];
    }
    $existing = $data['annual_leave'][$user] ?? [];
    $added = [];
    foreach ($validDates as $d) {
        if (!in_array($d, $existing)) {
            $existing[] = $d;
            $added[] = $d;
        }
    }
    $data['annual_leave'][$user] = array_values($existing);
    saveHolidayData($data);
    echo json_encode([
        'success' => true,
        'user' => $user,
        'added' => $added,
        'total' => count($data['annual_leave'][$user]),
        'message' => "用户 {$user}：成功添加 " . count($added) . ' 天年假' . (count($validDates) - count($added) > 0 ? '（' . (count($validDates) - count($added)) . ' 天已存在，跳过）' : '')
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== API: 年假管理 - 删除 ==========
if ($action === 'annual_remove') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
        http_response_code(405);
        echo json_encode(['error' => '请使用 POST 方法'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $input = json_decode(file_get_contents('php://input'), true);
    $user = isset($input['user']) ? trim($input['user']) : '';
    $removeDates = $input['dates'] ?? [];
    
    if ($user === '') {
        http_response_code(400);
        echo json_encode(['error' => '请提供 user 用户名'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!is_array($removeDates) || empty($removeDates)) {
        http_response_code(400);
        echo json_encode(['error' => '请提供日期数组'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    $data = loadHolidayData();
    $existing = $data['annual_leave'][$user] ?? [];
    $before = count($existing);
    $existing = array_values(array_diff($existing, $removeDates));
    $data['annual_leave'][$user] = $existing;
    saveHolidayData($data);
    echo json_encode([
        'success' => true,
        'user' => $user,
        'removed' => $before - count($existing),
        'total' => count($existing),
        'message' => "用户 {$user}：成功删除 " . ($before - count($existing)) . ' 天年假'
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

// ========== 非 API 请求：返回前端页面 ==========
header('Content-Type: text/html; charset=utf-8');

// 安全获取服务器变量，避免 PHP 8 下 undefined index 或 false 传参报错
$scheme = isset($_SERVER['REQUEST_SCHEME']) ? $_SERVER['REQUEST_SCHEME'] : (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http');
$host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';
$uri = isset($_SERVER['REQUEST_URI']) ? $_SERVER['REQUEST_URI'] : '/index.php';
$basePath = strtok($uri, '?');
if ($basePath === false) $basePath = '/index.php';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>节假日查询系统</title>
    <style>
        :root {
            --bg: #f5f7fa;
            --card-bg: #ffffff;
            --primary: #1a73e8;
            --primary-hover: #1557b0;
            --success: #0d904f;
            --danger: #d93025;
            --text: #202124;
            --text-secondary: #5f6368;
            --border: #dadce0;
            --shadow: 0 1px 3px rgba(0,0,0,0.08), 0 1px 2px rgba(0,0,0,0.06);
            --shadow-lg: 0 4px 12px rgba(0,0,0,0.1);
            --radius: 12px;
            --radius-sm: 8px;
            --transition: 0.2s ease;
            --holiday-bg: #fce8e6;
            --holiday-text: #c5221f;
            --workday-bg: #e6f4ea;
            --workday-text: #137333;
            --makeup-bg: #fef7e0;
            --makeup-text: #b06000;
        }

        * { margin: 0; padding: 0; box-sizing: border-box; }

        body {
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", "PingFang SC", "Hiragino Sans GB", "Microsoft YaHei", sans-serif;
            background: var(--bg);
            color: var(--text);
            min-height: 100vh;
            line-height: 1.6;
        }

        .container {
            max-width: 960px;
            margin: 0 auto;
            padding: 24px 16px;
        }

        /* Header */
        .header {
            text-align: center;
            padding: 32px 0 24px;
        }
        .header h1 {
            font-size: 28px;
            font-weight: 700;
            color: var(--text);
            letter-spacing: -0.5px;
        }
        .header p {
            color: var(--text-secondary);
            font-size: 14px;
            margin-top: 6px;
        }

        /* Cards */
        .card {
            background: var(--card-bg);
            border-radius: var(--radius);
            box-shadow: var(--shadow);
            padding: 24px;
            margin-bottom: 20px;
            border: 1px solid var(--border);
            transition: box-shadow var(--transition);
        }
        .card:hover { box-shadow: var(--shadow-lg); }
        .card-title {
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .card-title .icon {
            width: 32px;
            height: 32px;
            border-radius: 8px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }
        .icon-check { background: #e8f0fe; color: var(--primary); }
        .icon-update { background: #e6f4ea; color: var(--success); }
        .icon-list { background: #fef7e0; color: var(--makeup-text); }
        .icon-leave { background: #f3e8fd; color: #7b1fa2; }

        /* Form elements */
        .form-row {
            display: flex;
            gap: 12px;
            flex-wrap: wrap;
            align-items: flex-end;
        }
        .form-group {
            display: flex;
            flex-direction: column;
            gap: 6px;
            flex: 1;
            min-width: 180px;
        }
        .form-group label {
            font-size: 13px;
            font-weight: 500;
            color: var(--text-secondary);
        }
        input[type="date"],
        input[type="url"],
        input[type="text"] {
            padding: 10px 14px;
            border: 1.5px solid var(--border);
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-family: inherit;
            transition: border-color var(--transition), box-shadow var(--transition);
            outline: none;
            background: #fafafa;
        }
        input:focus {
            border-color: var(--primary);
            box-shadow: 0 0 0 3px rgba(26,115,232,0.12);
            background: #fff;
        }

        .btn {
            padding: 10px 20px;
            border: none;
            border-radius: var(--radius-sm);
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            transition: all var(--transition);
            font-family: inherit;
            white-space: nowrap;
            display: inline-flex;
            align-items: center;
            gap: 6px;
        }
        .btn-primary {
            background: var(--primary);
            color: #fff;
        }
        .btn-primary:hover { background: var(--primary-hover); }
        .btn-success {
            background: var(--success);
            color: #fff;
        }
        .btn-success:hover { background: #0b7d43; }
        .btn-outline {
            background: #fff;
            color: var(--primary);
            border: 1.5px solid var(--primary);
        }
        .btn-outline:hover { background: #e8f0fe; }
        .mode-btn.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }
        .btn:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }

        /* Result badge */
        .result-badge {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 18px;
            border-radius: 20px;
            font-size: 16px;
            font-weight: 600;
            margin-top: 12px;
        }
        .result-holiday {
            background: var(--holiday-bg);
            color: var(--holiday-text);
        }
        .result-work {
            background: var(--workday-bg);
            color: var(--workday-text);
        }
        .result-dot {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
        }
        .dot-holiday { background: var(--danger); }
        .dot-work { background: var(--success); }

        /* Holiday list */
        .holiday-list {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 12px;
        }
        .holiday-item {
            padding: 12px 16px;
            border-radius: var(--radius-sm);
            border: 1px solid var(--border);
            background: #fafafa;
            transition: transform var(--transition);
        }
        .holiday-item:hover { transform: translateY(-1px); }
        .holiday-item .name {
            font-weight: 600;
            font-size: 14px;
            margin-bottom: 4px;
        }
        .holiday-item .range {
            font-size: 13px;
            color: var(--text-secondary);
        }
        .holiday-item .makeup {
            font-size: 12px;
            color: var(--makeup-text);
            margin-top: 2px;
        }

        .tag {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
        }
        .tag-holiday { background: #fce8e6; color: #c5221f; }
        .tag-work { background: #e6f4ea; color: #137333; }
        .tag-makeup { background: #fef7e0; color: #b06000; }

        /* Status message */
        .status-msg {
            padding: 10px 14px;
            border-radius: var(--radius-sm);
            font-size: 13px;
            margin-top: 12px;
            display: none;
        }
        .status-msg.show { display: block; }
        .status-success { background: #e6f4ea; color: #137333; border: 1px solid #a8dab5; }
        .status-error { background: #fce8e6; color: #c5221f; border: 1px solid #f5c6cb; }
        .status-info { background: #e8f0fe; color: #1a56b0; border: 1px solid #a8c7fa; }

        /* Annual leave chips */
        .leave-chips {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            margin-top: 12px;
        }
        .leave-chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 13px;
            background: #f3e8fd;
            color: #7b1fa2;
            border: 1px solid #e1bee7;
            transition: all var(--transition);
        }
        .leave-chip .remove-btn {
            cursor: pointer;
            font-size: 16px;
            line-height: 1;
            opacity: 0.6;
            transition: opacity var(--transition);
            background: none;
            border: none;
            color: inherit;
            padding: 0 2px;
        }
        .leave-chip .remove-btn:hover { opacity: 1; color: var(--danger); }
        .leave-chip-empty {
            color: var(--text-secondary);
            font-size: 13px;
            background: none;
            border: none;
        }
        .leave-year-group {
            margin-bottom: 12px;
        }
        .leave-year-label {
            font-size: 13px;
            font-weight: 600;
            color: var(--text-secondary);
            margin-bottom: 6px;
        }
        .date-range-picker {
            display: flex;
            gap: 8px;
            align-items: flex-end;
            flex-wrap: wrap;
        }

        /* Loading spinner */
        .spinner {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid rgba(255,255,255,0.3);
            border-top-color: #fff;
            border-radius: 50%;
            animation: spin 0.6s linear infinite;
        }
        @keyframes spin { to { transform: rotate(360deg); } }

        /* Year selector */
        .year-selector {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
            margin-bottom: 16px;
        }
        .year-btn {
            padding: 6px 14px;
            border: 1.5px solid var(--border);
            border-radius: 20px;
            font-size: 13px;
            cursor: pointer;
            background: #fff;
            transition: all var(--transition);
            font-family: inherit;
        }
        .year-btn:hover { border-color: var(--primary); color: var(--primary); }
        .year-btn.active {
            background: var(--primary);
            color: #fff;
            border-color: var(--primary);
        }

        /* API info */
        .api-info {
            background: #f1f3f4;
            border-radius: var(--radius-sm);
            padding: 14px 18px;
            font-family: "SF Mono", "Fira Code", "Consolas", monospace;
            font-size: 13px;
            margin-top: 12px;
            word-break: break-all;
        }
        .api-info code {
            background: #e0e0e0;
            padding: 2px 6px;
            border-radius: 3px;
            font-size: 12px;
        }

        /* Responsive */
        @media (max-width: 600px) {
            .form-row { flex-direction: column; }
            .form-group { min-width: 100%; }
            .holiday-list { grid-template-columns: 1fr; }
            .container { padding: 12px; }
            .header h1 { font-size: 22px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>📅 节假日查询系统</h1>
            <p>基于国务院办公厅节假日安排通知，准确判断工作日与节假日，支持多人年假管理</p>
        </div>

        <!-- 日期查询 -->
        <div class="card">
            <div class="card-title">
                <span class="icon icon-check">🔍</span>
                日期查询
            </div>
            <div class="form-row">
                <div class="form-group">
                    <label for="checkDate">选择日期</label>
                    <input type="date" id="checkDate" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <button class="btn btn-primary" onclick="checkDate()">查询</button>
            </div>
            <div id="checkResult" style="margin-top: 12px;"></div>
        </div>

        <!-- 更新节假日 -->
        <div class="card">
            <div class="card-title">
                <span class="icon icon-update">🔄</span>
                更新节假日数据
            </div>
            <p style="font-size:13px;color:var(--text-secondary);margin-bottom:12px;">
                每年底国务院办公厅发布下一年节假日安排后，可通过下方两种方式更新。
            </p>
            <!-- 模式切换 -->
            <div style="display:flex;gap:8px;margin-bottom:12px;">
                <button class="btn btn-outline mode-btn active" id="modeUrlBtn" onclick="switchMode('url')" style="flex:1;">输入网址抓取</button>
                <button class="btn btn-outline mode-btn" id="modeContentBtn" onclick="switchMode('content')" style="flex:1;">粘贴网页内容</button>
            </div>
            <!-- URL 模式 -->
            <div id="urlModeDiv">
                <div class="form-row">
                    <div class="form-group" style="flex:3;">
                        <label for="sourceUrl">国务院通知网页地址</label>
                        <input type="url" id="sourceUrl" placeholder="https://www.gov.cn/zhengce/content/...">
                    </div>
                    <button class="btn btn-success" id="updateBtn" onclick="updateHolidays()">抓取并更新</button>
                </div>
            </div>
            <!-- 内容粘贴模式 -->
            <div id="contentModeDiv" style="display:none;">
                <div class="form-group" style="margin-bottom:12px;">
                    <label for="pasteContent">粘贴国务院通知网页内容（将网页正文全选复制后粘贴到下方）</label>
                    <textarea id="pasteContent" rows="8" placeholder="打开国务院节假日安排通知页面 → 全选页面内容 → 复制 → 粘贴到此处" style="width:100%;padding:10px 14px;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-size:14px;font-family:inherit;background:#fafafa;resize:vertical;outline:none;transition:border-color var(--transition),box-shadow var(--transition);"></textarea>
                </div>
                <button class="btn btn-success" id="parseBtn" onclick="parseContent()">解析并更新</button>
            </div>
            <div id="updateStatus" class="status-msg"></div>
        </div>

        <!-- 年假管理 -->
        <div class="card">
            <div class="card-title">
                <span class="icon icon-leave">🏖️</span>
                年假管理（多人）
            </div>
            <p style="font-size:13px;color:var(--text-secondary);margin-bottom:12px;">
                选择用户后添加/删除年假。不同用户年假独立存储，API 查询时通过 <code>&user=用户名</code> 区分。
            </p>
            <div class="date-range-picker" style="margin-bottom:12px;">
                <div class="form-group" style="min-width:160px;flex:0 0 auto;">
                    <label for="userSelect">选择用户</label>
                    <select id="userSelect" onchange="onUserChange()" style="padding:10px 14px;border:1.5px solid var(--border);border-radius:var(--radius-sm);font-size:14px;background:#fafafa;">
                        <option value="">— 选择用户 —</option>
                    </select>
                </div>
                <div class="form-group" style="min-width:140px;flex:0 0 auto;">
                    <label for="newUserInput">新用户名</label>
                    <input type="text" id="newUserInput" placeholder="如 zhangsan" style="padding:10px 14px;">
                </div>
                <button class="btn btn-outline" onclick="addUser()">添加用户</button>
                <button class="btn btn-outline" style="color:var(--danger);border-color:var(--danger);" onclick="deleteUser()">删除用户</button>
            </div>
            <div class="date-range-picker">
                <div class="form-group" style="min-width:140px;flex:0 0 auto;">
                    <label for="leaveStart">开始日期</label>
                    <input type="date" id="leaveStart">
                </div>
                <div class="form-group" style="min-width:140px;flex:0 0 auto;">
                    <label for="leaveEnd">结束日期</label>
                    <input type="date" id="leaveEnd">
                </div>
                <button class="btn btn-primary" id="addLeaveBtn" onclick="addAnnualLeave()">添加年假</button>
            </div>
            <div id="leaveStatus" class="status-msg"></div>
            <div id="leaveChips" class="leave-chips">
                <span class="leave-chip-empty">请先选择用户</span>
            </div>
        </div>

        <!-- 节假日列表 -->
        <div class="card">
            <div class="card-title">
                <span class="icon icon-list">📋</span>
                节假日安排一览
            </div>
            <div class="year-selector" id="yearSelector"></div>
            <div class="holiday-list" id="holidayList">
                <p style="color:var(--text-secondary);font-size:14px;">加载中...</p>
            </div>
        </div>

        <!-- API 说明 -->
        <div class="card">
            <div class="card-title">
                <span class="icon icon-check">🔌</span>
                API 接口说明
            </div>
            <div class="api-info">
                <strong>查询日期（完整模式）：</strong><br>
                <code>GET ...?action=check&date=2026-01-01</code><br>
                <strong>返回：</strong> <code>{"code": 1, "date": "2026-01-01", "info": "元旦假期", "user": null}</code><br>
                <small>code=0 工作日，code=1 放假；不带 user 只查公共节假日</small><br><br>

                <strong>查询日期（精简模式）：</strong><br>
                <code>GET ...?action=check&date=2026-01-01&simple=1</code><br>
                <strong>返回：</strong> <code>1</code><br>
                <small>只返回 0 或 1，适合 Tasker</small><br><br>

                <strong>查询某用户（含年假）：</strong><br>
                <code>GET ...?action=check&date=2026-08-14&user=zhangsan&simple=1</code><br>
                <strong>返回：</strong> <code>1</code><br>
                <small>带 user 会叠加该用户的年假判断</small><br><br>

                <strong>说明：</strong> 日期支持 <code>2026-1-1</code>（不带零）和 <code>2026-01-01</code> 两种格式
            </div>
        </div>
    </div>

    <script>
        // ========== 页面初始化 ==========
        document.addEventListener('DOMContentLoaded', () => {
            loadYears();
            loadUsers();
            const today = '<?php echo date("Y-m-d"); ?>';
            document.getElementById('checkDate').value = today;
            document.getElementById('leaveStart').value = today;
            document.getElementById('leaveEnd').value = today;
            checkDate();
        });

        // ========== 日期查询 ==========
        async function checkDate() {
            const date = document.getElementById('checkDate').value;
            const resultDiv = document.getElementById('checkResult');
            
            if (!date) {
                resultDiv.innerHTML = '<p style="color:var(--text-secondary);">请选择日期</p>';
                return;
            }

            try {
                const resp = await fetch(`?action=check&date=${date}`);
                const data = await resp.json();
                
                if (data.error) {
                    resultDiv.innerHTML = `<p style="color:var(--danger);">${data.error}</p>`;
                    return;
                }

                const isHoliday = data.code === 1;
                const badgeClass = isHoliday ? 'result-holiday' : 'result-work';
                const dotClass = isHoliday ? 'dot-holiday' : 'dot-work';
                const label = isHoliday ? '放假' : '工作日';
                const info = data.info ? ` — ${data.info}` : '';
                
                resultDiv.innerHTML = `
                    <div class="result-badge ${badgeClass}">
                        <span class="result-dot ${dotClass}"></span>
                        <span>${date} ${label}${info}</span>
                        <span style="font-size:12px;opacity:0.7;">(code=${data.code})</span>
                    </div>
                `;
            } catch (e) {
                resultDiv.innerHTML = `<p style="color:var(--danger);">查询失败：${e.message}</p>`;
            }
        }

        // ========== 更新节假日 ==========
        let updateMode = 'url';

        function switchMode(mode) {
            updateMode = mode;
            const urlBtn = document.getElementById('modeUrlBtn');
            const contentBtn = document.getElementById('modeContentBtn');
            const urlDiv = document.getElementById('urlModeDiv');
            const contentDiv = document.getElementById('contentModeDiv');
            
            if (mode === 'url') {
                urlBtn.classList.add('active');
                contentBtn.classList.remove('active');
                urlDiv.style.display = '';
                contentDiv.style.display = 'none';
            } else {
                urlBtn.classList.remove('active');
                contentBtn.classList.add('active');
                urlDiv.style.display = 'none';
                contentDiv.style.display = '';
            }
            // 清除上次的状态
            const statusDiv = document.getElementById('updateStatus');
            statusDiv.className = 'status-msg';
            statusDiv.style.display = 'none';
        }

        async function updateHolidays() {
            const url = document.getElementById('sourceUrl').value.trim();
            const btn = document.getElementById('updateBtn');
            const statusDiv = document.getElementById('updateStatus');
            
            if (!url) {
                showStatus(statusDiv, '请填写网页地址', 'error');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> 抓取中...（可能需要数秒）';
            statusDiv.className = 'status-msg';
            statusDiv.style.display = 'none';

            try {
                const resp = await fetch('?action=update', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ url: url })
                });
                const respText = await resp.text();
                let data;
                try {
                    data = JSON.parse(respText);
                } catch (jsonErr) {
                    const preview = respText.substring(0, 200);
                    showStatus(statusDiv, `❌ 服务器返回了非JSON响应（HTTP ${resp.status}）：${preview}`, 'error');
                    return;
                }
                
                if (data.error) {
                    showStatus(statusDiv, `❌ ${data.error}`, 'error');
                } else {
                    showStatus(statusDiv, `✅ ${data.message}`, 'success');
                    loadYears();
                }
            } catch (e) {
                showStatus(statusDiv, `❌ 请求失败：${e.message}。可能原因：服务器无法访问外网（gov.cn），建议改用"粘贴网页内容"模式。`, 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '抓取并更新';
            }
        }

        async function parseContent() {
            const content = document.getElementById('pasteContent').value.trim();
            const btn = document.getElementById('parseBtn');
            const statusDiv = document.getElementById('updateStatus');
            
            if (!content) {
                showStatus(statusDiv, '请粘贴国务院通知网页内容', 'error');
                return;
            }

            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> 解析中...';
            statusDiv.className = 'status-msg';
            statusDiv.style.display = 'none';

            try {
                const resp = await fetch('?action=update', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ content: content })
                });
                const respText = await resp.text();
                let data;
                try {
                    data = JSON.parse(respText);
                } catch (jsonErr) {
                    const preview = respText.substring(0, 200);
                    showStatus(statusDiv, `❌ 服务器返回了非JSON响应（HTTP ${resp.status}）：${preview}`, 'error');
                    return;
                }
                
                if (data.error) {
                    showStatus(statusDiv, `❌ ${data.error}`, 'error');
                } else {
                    showStatus(statusDiv, `✅ ${data.message}`, 'success');
                    loadYears();
                }
            } catch (e) {
                showStatus(statusDiv, `❌ 请求失败：${e.message}`, 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '解析并更新';
            }
        }

        function showStatus(el, msg, type) {
            el.textContent = msg;
            el.className = `status-msg show status-${type}`;
        }

        // ========== 加载年份列表 ==========
        async function loadYears() {
            try {
                const resp = await fetch('?action=list');
                const data = await resp.json();
                const currentYear = new Date().getFullYear();
                let years = data.years || [];
                
                if (years.length === 0) {
                    years = [currentYear];
                }
                
                const yearSelector = document.getElementById('yearSelector');
                yearSelector.innerHTML = years.map(y => 
                    `<button class="year-btn ${y == currentYear ? 'active' : ''}" onclick="selectYear(${y}, this)">${y} 年</button>`
                ).join('');
                
                // 默认展示最新年份
                const defaultYear = years.includes(currentYear) ? currentYear : years[0];
                loadHolidayList(defaultYear);
            } catch (e) {
                console.error('加载年份失败:', e);
            }
        }

        function selectYear(year, btn) {
            document.querySelectorAll('.year-btn').forEach(b => b.classList.remove('active'));
            btn.classList.add('active');
            loadHolidayList(year);
        }

        // ========== 加载节假日列表 ==========
        async function loadHolidayList(year) {
            const listDiv = document.getElementById('holidayList');
            
            try {
                const resp = await fetch(`?action=list&year=${year}`);
                if (!resp.ok) {
                    listDiv.innerHTML = `<p style="color:var(--text-secondary);font-size:14px;">暂无 ${year} 年节假日数据，请通过上方"更新节假日数据"功能抓取。</p>`;
                    return;
                }
                
                const data = await resp.json();
                
                if (!data.holiday_names || data.holiday_names.length === 0) {
                    listDiv.innerHTML = `<p style="color:var(--text-secondary);font-size:14px;">暂无详细节假日信息</p>`;
                    return;
                }
                
                let html = '';
                for (const h of data.holiday_names) {
                    const makeupDays = h.makeup || [];
                    const makeupStr = makeupDays.length > 0 
                        ? `<div class="makeup">⚠️ 调休上班：${makeupDays.join('、')}</div>` 
                        : '';
                    
                    html += `
                        <div class="holiday-item">
                            <div class="name">${h.name}</div>
                            <div class="range">📅 ${h.start} ~ ${h.end}（共${h.days}天）</div>
                            ${makeupStr}
                        </div>
                    `;
                }
                listDiv.innerHTML = html;
            } catch (e) {
                listDiv.innerHTML = `<p style="color:var(--danger);">加载失败：${e.message}</p>`;
            }
        }

        // ========== 年假管理（多人）==========
        let currentUser = '';

        // 加载用户列表到下拉框
        async function loadUsers() {
            const sel = document.getElementById('userSelect');
            try {
                const resp = await fetch('?action=users_list');
                const data = await resp.json();
                const users = data.users || [];
                const prev = currentUser;
                sel.innerHTML = '<option value="">— 选择用户 —</option>' +
                    users.map(u => `<option value="${u.user}">${u.user}（${u.count} 天）</option>`).join('');
                if (prev && users.some(u => u.user === prev)) {
                    sel.value = prev;
                    currentUser = prev;
                    loadAnnualLeave();
                }
            } catch (e) {
                sel.innerHTML = '<option value="">加载失败</option>';
            }
        }

        // 用户选择变化
        function onUserChange() {
            currentUser = document.getElementById('userSelect').value;
            if (currentUser) {
                loadAnnualLeave();
            } else {
                document.getElementById('leaveChips').innerHTML = '<span class="leave-chip-empty">请先选择用户</span>';
            }
        }

        // 添加新用户
        async function addUser() {
            const name = document.getElementById('newUserInput').value.trim();
            if (!name) {
                alert('请输入用户名');
                return;
            }
            // 通过 annual_add 添加空日期来创建用户
            try {
                const resp = await fetch('?action=annual_add', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user: name, dates: ['2000-01-01'] })
                });
                const data = await resp.json();
                if (data.error) {
                    alert(data.error);
                    return;
                }
                // 删掉占位日期
                await fetch('?action=annual_remove', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user: name, dates: ['2000-01-01'] })
                });
                document.getElementById('newUserInput').value = '';
                currentUser = name;
                await loadUsers();
                document.getElementById('userSelect').value = name;
                loadAnnualLeave();
            } catch (e) {
                alert('添加用户失败：' + e.message);
            }
        }

        // 删除用户
        async function deleteUser() {
            if (!currentUser) {
                alert('请先选择要删除的用户');
                return;
            }
            if (!confirm(`确认删除用户「${currentUser}」及其所有年假记录？`)) return;
            try {
                // 获取该用户所有日期然后删除
                const resp = await fetch(`?action=annual_list&user=${encodeURIComponent(currentUser)}`);
                const data = await resp.json();
                const dates = data.annual_leave || [];
                if (dates.length > 0) {
                    await fetch('?action=annual_remove', {
                        method: 'POST',
                        headers: { 'Content-Type': 'application/json' },
                        body: JSON.stringify({ user: currentUser, dates: dates })
                    });
                }
                // 彻底清除用户键
                await fetch('?action=user_delete', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user: currentUser })
                });
                currentUser = '';
                await loadUsers();
                document.getElementById('leaveChips').innerHTML = '<span class="leave-chip-empty">请先选择用户</span>';
            } catch (e) {
                alert('删除用户失败：' + e.message);
            }
        }

        // 加载当前用户的年假
        async function loadAnnualLeave() {
            const chipsDiv = document.getElementById('leaveChips');
            if (!currentUser) {
                chipsDiv.innerHTML = '<span class="leave-chip-empty">请先选择用户</span>';
                return;
            }
            try {
                const resp = await fetch(`?action=annual_list&user=${encodeURIComponent(currentUser)}`);
                const data = await resp.json();
                const leaves = data.annual_leave || [];
                
                if (leaves.length === 0) {
                    chipsDiv.innerHTML = '<span class="leave-chip-empty">该用户暂无年假记录</span>';
                    return;
                }
                
                // 按年份分组
                const byYear = {};
                leaves.forEach(d => {
                    const y = d.substring(0, 4);
                    if (!byYear[y]) byYear[y] = [];
                    byYear[y].push(d);
                });
                
                let html = '';
                const years = Object.keys(byYear).sort().reverse();
                for (const y of years) {
                    html += `<div class="leave-year-group">`;
                    html += `<div class="leave-year-label">${y} 年（共 ${byYear[y].length} 天）</div>`;
                    html += `<div class="leave-chips">`;
                    byYear[y].forEach(d => {
                        html += `
                            <span class="leave-chip">
                                ${d}
                                <button class="remove-btn" onclick="removeAnnualLeave('${d}')" title="删除">×</button>
                            </span>`;
                    });
                    html += `</div></div>`;
                }
                chipsDiv.innerHTML = html;
            } catch (e) {
                chipsDiv.innerHTML = `<span style="color:var(--danger);">加载失败：${e.message}</span>`;
            }
        }

        async function addAnnualLeave() {
            if (!currentUser) {
                alert('请先选择用户');
                return;
            }
            const startVal = document.getElementById('leaveStart').value;
            const endVal = document.getElementById('leaveEnd').value;
            const btn = document.getElementById('addLeaveBtn');
            const statusDiv = document.getElementById('leaveStatus');
            
            if (!startVal || !endVal) {
                showStatus(statusDiv, '请选择开始和结束日期', 'error');
                return;
            }
            
            // 生成日期范围内的所有日期
            const dates = [];
            const start = new Date(startVal);
            const end = new Date(endVal);
            if (start > end) {
                showStatus(statusDiv, '开始日期不能晚于结束日期', 'error');
                return;
            }
            for (let d = new Date(start); d <= end; d.setDate(d.getDate() + 1)) {
                dates.push(d.toISOString().substring(0, 10));
            }
            
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner"></span> 添加中...';
            statusDiv.className = 'status-msg';
            statusDiv.style.display = 'none';
            
            try {
                const resp = await fetch('?action=annual_add', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user: currentUser, dates: dates })
                });
                const data = await resp.json();
                
                if (data.error) {
                    showStatus(statusDiv, `❌ ${data.error}`, 'error');
                } else {
                    showStatus(statusDiv, `✅ ${data.message}`, 'success');
                    loadAnnualLeave();
                    loadUsers();
                }
            } catch (e) {
                showStatus(statusDiv, `❌ 请求失败：${e.message}`, 'error');
            } finally {
                btn.disabled = false;
                btn.innerHTML = '添加年假';
            }
        }

        async function removeAnnualLeave(date) {
            if (!currentUser) return;
            try {
                const resp = await fetch('?action=annual_remove', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ user: currentUser, dates: [date] })
                });
                const data = await resp.json();
                if (data.success) {
                    loadAnnualLeave();
                    loadUsers();
                }
            } catch (e) {
                alert('删除失败：' + e.message);
            }
        }
    </script>
</body>
</html>
<?php
// ========== 以下是 PHP 函数定义 ==========

/**
 * 加载节假日数据
 */
function loadHolidayData(): array {
    $file = __DIR__ . '/holidays.json';
    if (!file_exists($file)) {
        return [];
    }
    $json = file_get_contents($file);
    $data = json_decode($json, true) ?: [];
    
    // 自动迁移：旧格式 annual_leave 为数组，转为 {用户名: [日期]} 对象
    if (isset($data['annual_leave']) && is_array($data['annual_leave']) && !empty($data['annual_leave'])) {
        $first = reset($data['annual_leave']);
        if (is_string($first)) {
            // 旧格式：索引数组（日期字符串列表）→ 迁移到 default 用户
            $data['annual_leave'] = ['default' => array_values($data['annual_leave'])];
            saveHolidayData($data);
        }
    }
    
    return $data;
}

/**
 * 保存节假日数据
 */
function saveHolidayData(array $data): void {
    $file = __DIR__ . '/holidays.json';
    file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
}

/**
 * 获取某用户的年假日期列表
 */
function getUserAnnualLeave(array $data, ?string $user): array {
    if ($user === null || $user === '') return [];
    return $data['annual_leave'][$user] ?? [];
}

/**
 * 判断日期是否为工作日
 */
function isWorkingDay(string $date, array $data, ?string $user = null): bool {
    $year = substr($date, 0, 4);
    
    // 年假 → 放假（最高优先级，按用户隔离）
    if ($user !== null && $user !== '' && in_array($date, getUserAnnualLeave($data, $user))) {
        return false;
    }
    
    // 如果该年份有节假日数据，优先使用
    if (isset($data[$year])) {
        $yearData = $data[$year];
        
        // 调休上班日 → 工作日
        if (in_array($date, $yearData['workdays'] ?? [])) {
            return true;
        }
        
        // 法定节假日 → 放假
        if (in_array($date, $yearData['holidays'] ?? [])) {
            return false;
        }
    }
    
    // 默认：周末放假，工作日上班
    $dayOfWeek = (int)date('N', strtotime($date));
    return $dayOfWeek <= 5; // 1-5 工作日，6-7 周末
}

/**
 * 获取日期信息描述
 */
function getDateInfo(string $date, array $data, ?string $user = null): string {
    $year = substr($date, 0, 4);
    
    // 年假
    if ($user !== null && $user !== '' && in_array($date, getUserAnnualLeave($data, $user))) {
        return '年假休息';
    }
    
    if (isset($data[$year])) {
        $yearData = $data[$year];
        
        // 检查调休上班日
        if (in_array($date, $yearData['workdays'] ?? [])) {
            return '调休上班日（原为周末）';
        }
        
        // 检查法定节假日
        if (in_array($date, $yearData['holidays'] ?? [])) {
            // 尝试匹配节假日名称
            if (isset($yearData['holiday_names'])) {
                foreach ($yearData['holiday_names'] as $h) {
                    if ($date >= $h['start'] && $date <= $h['end']) {
                        return $h['name'] . '假期';
                    }
                }
            }
            return '法定节假日';
        }
    }
    
    $dayOfWeek = (int)date('N', strtotime($date));
    return $dayOfWeek <= 5 ? '普通工作日' : '周末休息';
}

/**
 * 通用 URL 抓取函数（优先 cURL，回退 file_get_contents）
 */
function fetchUrl(string $url): string|false {
    // 优先使用 cURL
    if (function_exists('curl_init')) {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 5,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
            CURLOPT_ENCODING => 'gzip, deflate',
        ]);
        $html = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        
        if ($html !== false && $httpCode >= 200 && $httpCode < 400) {
            return $html;
        }
    }
    
    // 回退 file_get_contents
    $context = stream_context_create([
        'http' => [
            'timeout' => 30,
            'user_agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36',
            'follow_location' => true,
            'max_redirects' => 5
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false
        ]
    ]);
    
    return @file_get_contents($url, false, $context);
}

/**
 * 解析国务院节假日安排网页（通过 URL 抓取）
 */
function parseHolidayPage(string $url): array {
    // 抓取页面（优先使用 cURL，失败则回退 file_get_contents）
    $html = fetchUrl($url);
    if ($html === false) {
        return ['error' => '无法访问该网页，请检查地址是否正确或网络连接。如果服务器无法访问外网，请改用"粘贴网页内容"方式。'];
    }
    
    return parseHolidayContent($html);
}

/**
 * 解析节假日安排内容（直接接受 HTML 或纯文本）
 * 支持：完整 HTML 页面、网页正文文本、复制的通知全文
 */
function parseHolidayContent(string $rawContent): array {
    // 如果内容包含 HTML 标签，先去标签
    $text = strip_tags($rawContent);
    // 规范化空白字符（移除所有空白、换行、制表符）
    $text = preg_replace('/\s+/', '', $text);
    
    if (empty($text) || strlen($text) < 20) {
        return ['error' => '内容为空或过短，请确认粘贴了完整的国务院节假日通知网页内容'];
    }
    
    // 提取年份
    if (!preg_match('/关于(\d{4})年/', $text, $yearMatch)) {
        return ['error' => '无法从内容中提取年份信息（需包含"关于XXXX年"字样），请确认粘贴的是国务院节假日安排通知'];
    }
    $year = intval($yearMatch[1]);
    
    // 提取正文内容（从"通知如下"或"经国务院批准"到"国务院办公厅"）
    $body = '';
    if (preg_match('/通知如下(.+?)(?=国务院办公厅)/s', $text, $bodyMatch)) {
        $body = $bodyMatch[1];
    } elseif (preg_match('/经国务院批准(.+?)(?=国务院办公厅)/s', $text, $bodyMatch)) {
        $body = $bodyMatch[1];
    } else {
        return ['error' => '无法从内容中提取节假日安排正文，请确认包含"通知如下"到"国务院办公厅"之间的内容'];
    }
    
    // 分割各节假日段落（允许名称中包含"、"，如"国庆节、中秋节"）
    preg_match_all('/([一二三四五六七八九十]+)、([^，。]{1,20})[：:](.*?)(?=[一二三四五六七八九十]+、[^，。]{1,20}[：:]|$)/u', $body, $sectionMatches, PREG_SET_ORDER);
    
    $allHolidays = [];
    $allWorkdays = [];
    $holidayNames = [];
    
    foreach ($sectionMatches as $sec) {
        $name = $sec[2];
        $section = $sec[3];
        
        if (empty($name) || empty($section)) continue;
        
        // 解析节假日日期范围
        $holidayDates = parseHolidayRange($section, $year);
        // 解析调休上班日
        $makeupDates = parseMakeupDays($section, $year);
        
        if (empty($holidayDates)) continue;
        
        $allHolidays = array_merge($allHolidays, $holidayDates);
        $allWorkdays = array_merge($allWorkdays, $makeupDates);
        
        $holidayNames[] = [
            'name' => $name,
            'start' => $holidayDates[0],
            'end' => $holidayDates[count($holidayDates) - 1],
            'days' => count($holidayDates),
            'makeup' => $makeupDates
        ];
    }
    
    if (empty($allHolidays)) {
        return ['error' => '未能解析出节假日日期。请确认粘贴的是完整的国务院节假日安排通知页面内容（包含一、二、三等条目）'];
    }
    
    // 去重排序
    $allHolidays = array_unique($allHolidays);
    sort($allHolidays);
    $allWorkdays = array_unique($allWorkdays);
    sort($allWorkdays);
    
    return [
        'year' => (string)$year,
        'holidays' => array_values($allHolidays),
        'workdays' => array_values($allWorkdays),
        'holiday_names' => $holidayNames
    ];
}

/**
 * 解析节假日日期范围（如 "1月1日（周四）至3日（周六）放假调休"）
 */
function parseHolidayRange(string $text, int $year): array {
    $dates = [];
    
    // 尝试匹配完整格式：X月Y日...至...A月B日...放
    if (preg_match('/(\d+)月(\d+)日.{0,50}?至.{0,20}?(\d+)月(\d+)日.{0,20}?放/u', $text, $m)) {
        $startM = intval($m[1]);
        $startD = intval($m[2]);
        $endM = intval($m[3]);
        $endD = intval($m[4]);
        return expandDateRange($year, $startM, $startD, $endM, $endD);
    }
    
    // 尝试匹配省略月份的格式：X月Y日...至...B日...放（同月）
    if (preg_match('/(\d+)月(\d+)日.{0,50}?至.{0,20}?(\d+)日.{0,20}?放/u', $text, $m)) {
        $startM = intval($m[1]);
        $startD = intval($m[2]);
        $endD = intval($m[3]);
        return expandDateRange($year, $startM, $startD, $startM, $endD);
    }
    
    // 尝试匹配单天格式：X月Y日...放假（无"至"）
    if (preg_match('/(\d+)月(\d+)日.{0,30}?放/u', $text, $m)) {
        $month = intval($m[1]);
        $day = intval($m[2]);
        if (checkdate($month, $day, $year)) {
            $date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            return [$date];
        }
    }
    
    return $dates;
}

/**
 * 解析调休上班日（如 "1月4日（周日）上班"）
 */
function parseMakeupDays(string $text, int $year): array {
    $dates = [];
    
    // 匹配所有"X月Y日"后面跟着"上班"的日期
    // 使用 lookahead 确保每个日期都被捕获
    if (preg_match_all('/(\d+)月(\d+)日(?=[^，。]*上班)/u', $text, $matches, PREG_SET_ORDER)) {
        foreach ($matches as $m) {
            $month = intval($m[1]);
            $day = intval($m[2]);
            // 验证日期有效性
            if (checkdate($month, $day, $year)) {
                $dates[] = sprintf('%04d-%02d-%02d', $year, $month, $day);
            }
        }
    }
    
    return $dates;
}

/**
 * 展开日期范围
 */
function expandDateRange(int $year, int $startM, int $startD, int $endM, int $endD): array {
    $dates = [];
    $start = mktime(0, 0, 0, $startM, $startD, $year);
    $end = mktime(0, 0, 0, $endM, $endD, $year);
    
    if ($start === false || $end === false) return $dates;
    if ($start > $end) return $dates;
    
    for ($t = $start; $t <= $end; $t += 86400) {
        $dates[] = date('Y-m-d', $t);
    }
    
    return $dates;
}