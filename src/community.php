<?php
/**
 * 班级公告 / 班费投票（轻量存储于 system_meta，适合单班级）
 */
function communityLoad(string $key): array {
    $raw = getMeta($key, '[]');
    $data = json_decode($raw, true);
    return is_array($data) ? $data : [];
}
function communitySave(string $key, array $data): void {
    setMeta($key, json_encode($data, JSON_UNESCAPED_UNICODE));
}
function communityUser(): array {
    $u = currentUser();
    return ['id' => (int)($u['id'] ?? 0), 'name' => (string)($u['username'] ?? '')];
}

function handleAnnouncements(string $method) {
    requireLogin();
    $key = 'announcements_v1';
    $items = communityLoad($key);
    if ($method === 'GET') {
        $uid = (int)(currentUser()['id'] ?? 0);
        $now = date('Y-m-d H:i:s');
        $out = [];
        foreach ($items as $it) {
            if (!empty($it['expires_at']) && (string)$it['expires_at'] < $now) continue;
            $readBy = is_array($it['read_by'] ?? null) ? $it['read_by'] : [];
            $out[] = [
                'id' => (int)$it['id'], 'title' => (string)$it['title'], 'content' => (string)$it['content'],
                'pinned' => !empty($it['pinned']), 'created_at' => (string)$it['created_at'],
                'created_by' => (string)($it['created_by'] ?? ''), 'read' => $uid > 0 && isset($readBy[(string)$uid]),
            ];
        }
        jsonOutput(['items' => $out]);
    }
    requirePermission('manageRoster');
    requireCsrfToken();
    $input = jsonInput();
    $action = (string)($input['op'] ?? $input['operation'] ?? 'create');
    $uid = (int)(currentUser()['id'] ?? 0);
    $name = (string)(currentUser()['username'] ?? '');
    if ($action === 'create') {
        $title = trim((string)($input['title'] ?? ''));
        $text = trim((string)($input['content'] ?? ''));
        if ($title === '' || $text === '') jsonOutput(['error' => '请填写公告标题和内容'], 400);
        $items[] = [
            'id' => (int)(microtime(true) * 1000), 'title' => mb_substr($title, 0, 100),
            'content' => mb_substr($text, 0, 5000), 'pinned' => !empty($input['pinned']),
            'created_by' => $name, 'created_at' => date('Y-m-d H:i:s'),
            'expires_at' => trim((string)($input['expires_at'] ?? '')), 'read_by' => [],
        ];
        communitySave($key, array_slice($items, -100));
        addLog($uid, $name, 'announcement_create', 'system', null, ['title' => $title]);
        jsonOutput(['ok' => true]);
    }
    if ($action === 'delete') {
        $id = (int)($input['id'] ?? 0);
        communitySave($key, array_values(array_filter($items, function ($it) use ($id) { return (int)$it['id'] !== $id; })));
        addLog($uid, $name, 'announcement_delete', 'system', $id);
        jsonOutput(['ok' => true]);
    }
    if ($action === 'read') {
        $id = (int)($input['id'] ?? 0);
        foreach ($items as &$it) {
            if ((int)$it['id'] === $id) {
                $readBy = is_array($it['read_by'] ?? null) ? $it['read_by'] : [];
                $readBy[(string)$uid] = date('Y-m-d H:i:s');
                $it['read_by'] = $readBy;
            }
        }
        unset($it);
        communitySave($key, $items);
        jsonOutput(['ok' => true]);
    }
    jsonOutput(['error' => '未知操作'], 400);
}

function handleVotes(string $method) {
    requireLogin();
    $key = 'votes_v1';
    $votes = communityLoad($key);
    $user = communityUser();
    if ($method === 'GET') {
        $out = [];
        foreach ($votes as $v) {
            $ballots = is_array($v['ballots'] ?? null) ? $v['ballots'] : [];
            $counts = [];
            foreach ($v['options'] ?? [] as $opt) $counts[(string)$opt] = 0;
            foreach ($ballots as $pick) foreach ((array)$pick as $opt) if (isset($counts[(string)$opt])) $counts[(string)$opt]++;
            $my = $ballots[(string)$user['id']] ?? null;
            $out[] = [
                'id' => (int)$v['id'], 'title' => (string)$v['title'], 'options' => $v['options'] ?? [],
                'deadline' => (string)($v['deadline'] ?? ''), 'closed' => (bool)($v['closed'] ?? false),
                'multi' => (bool)($v['multi'] ?? false), 'counts' => $counts, 'total' => count($ballots),
                'my' => is_array($my) ? array_values($my) : [],
            ];
        }
        jsonOutput(['items' => $out]);
    }
    requireCsrfToken();
    $input = jsonInput();
    $action = (string)($input['op'] ?? $input['operation'] ?? '');
    if ($action === 'cast') {
        if ($user['id'] <= 0) jsonOutput(['error' => '访客不能投票'], 403);
        $id = (int)($input['id'] ?? 0);
        $picks = $input['options'] ?? [];
        if (is_string($picks)) $picks = json_decode($picks, true) ?: [];
        $picks = array_values(array_unique(array_map('strval', (array)$picks)));
        foreach ($votes as &$v) {
            if ((int)$v['id'] !== $id) continue;
            if (!empty($v['closed']) || (!empty($v['deadline']) && strtotime((string)$v['deadline']) < time())) jsonOutput(['error' => '投票已结束'], 400);
            $allowed = array_map('strval', $v['options'] ?? []);
            $picks = array_values(array_intersect($picks, $allowed));
            if (!$picks) jsonOutput(['error' => '请选择选项'], 400);
            if (empty($v['multi']) && count($picks) > 1) jsonOutput(['error' => '单选投票只能选一项'], 400);
            $v['ballots'] = is_array($v['ballots'] ?? null) ? $v['ballots'] : [];
            $v['ballots'][(string)$user['id']] = $picks;
            communitySave($key, $votes);
            jsonOutput(['ok' => true]);
        }
        jsonOutput(['error' => '投票不存在'], 404);
    }
    if ($action === 'create' || $action === 'close') {
        requirePermission('manageRoster');
        if ($action === 'close') {
            $id = (int)($input['id'] ?? 0);
            foreach ($votes as &$v) if ((int)$v['id'] === $id) $v['closed'] = true;
            unset($v);
            communitySave($key, $votes);
            jsonOutput(['ok' => true]);
        }
        $title = trim((string)($input['title'] ?? ''));
        $options = $input['options'] ?? [];
        if (is_string($options)) $options = preg_split('/[\n,，]+/', $options);
        $options = array_values(array_filter(array_map('trim', (array)$options), function ($x) { return $x !== ''; }));
        if ($title === '' || count($options) < 2) jsonOutput(['error' => '请填写投票标题和至少两个选项'], 400);
        $votes[] = [
            'id' => (int)(microtime(true) * 1000), 'title' => mb_substr($title, 0, 200),
            'options' => array_slice(array_map(function ($x) { return mb_substr($x, 0, 80); }, $options), 0, 10),
            'deadline' => trim((string)($input['deadline'] ?? '')), 'multi' => !empty($input['multi']),
            'created_by' => $user['name'], 'created_at' => date('Y-m-d H:i:s'), 'closed' => false, 'ballots' => [],
        ];
        communitySave($key, array_slice($votes, -50));
        addLog($user['id'], $user['name'], 'vote_create', 'system', null, ['title' => $title]);
        jsonOutput(['ok' => true]);
    }
    jsonOutput(['error' => '未知操作'], 400);
}
