<?php
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
require __DIR__ . '/db.php';

function respond(array $data, int $status = 200): never {
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    exit;
}
function readInput(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    if (!is_array($data)) respond(['error' => 'Invalid JSON request.'], 400);
    return $data;
}
function normalizeRecord(array $row): array {
    $data = json_decode($row['record_data'], true);
    if (!is_array($data)) $data = [];
    $data['__backendId'] = (string)$row['id'];
    return $data;
}
$action = $_GET['action'] ?? '';
try {
    if ($action === 'list' && $_SERVER['REQUEST_METHOD'] === 'GET') {
        $stmt = $pdo->query('SELECT id, record_data FROM app_records ORDER BY id ASC');
        $records = array_map('normalizeRecord', $stmt->fetchAll());
        respond(['data' => $records]);
    }
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') respond(['error' => 'Method not allowed.'], 405);
    $input = readInput();

    if ($action === 'create') {
        $record = $input['data'] ?? null;
        if (!is_array($record) || empty($record['type'])) respond(['error' => 'Missing record data.'], 400);
        unset($record['__backendId']);
        $stmt = $pdo->prepare('INSERT INTO app_records (record_data) VALUES (:data)');
        $stmt->execute(['data' => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)]);
        respond(['isOk' => true, 'id' => (string)$pdo->lastInsertId()], 201);
    }
    if ($action === 'update') {
        $record = $input['data'] ?? null;
        $id = is_array($record) ? filter_var($record['__backendId'] ?? null, FILTER_VALIDATE_INT) : false;
        if (!is_array($record) || !$id) respond(['error' => 'Missing record ID or data.'], 400);
        unset($record['__backendId']);
        $stmt = $pdo->prepare('UPDATE app_records SET record_data = :data WHERE id = :id');
        $stmt->execute(['data' => json_encode($record, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR), 'id' => $id]);
        if ($stmt->rowCount() === 0) {
            $check = $pdo->prepare('SELECT id FROM app_records WHERE id = ?');
            $check->execute([$id]);
            if (!$check->fetch()) respond(['error' => 'Record not found.'], 404);
        }
        respond(['isOk' => true]);
    }
    if ($action === 'delete') {
        $id = filter_var($input['id'] ?? null, FILTER_VALIDATE_INT);
        if (!$id) respond(['error' => 'Missing record ID.'], 400);
        $stmt = $pdo->prepare('DELETE FROM app_records WHERE id = ?');
        $stmt->execute([$id]);
        if ($stmt->rowCount() === 0) respond(['error' => 'Record not found.'], 404);
        respond(['isOk' => true]);
    }
    respond(['error' => 'Unknown action.'], 400);
} catch (Throwable $e) {
    error_log($e->getMessage());
    respond(['error' => 'The database operation failed. Check the PHP error log.'], 500);
}
