<?php
session_start();
if (!isset($_SESSION['id'])) exit; 
header('Content-Type: application/json');

// Database setup
$db = new SQLite3('..\..\db\chat.sqlite',SQLITE3_OPEN_READONLY);
$db->exec('PRAGMA journal_mode=WAL');
$db->busyTimeout(5000); // Wait up to 5 seconds if database is locked
$db->exec("attach database '" . __DIR__ . "/../../db/MarvinUsers.sqlite' as dbu;");

//$filter='';
//if (isset($_REQUEST['after'])) $filter ='WHERE id>'.intval($_REQUEST['after']);
//$results = $db->query('SELECT * FROM messages '.$filter.' ORDER BY timestamp ASC');

//$results = $db->query('SELECT * FROM messages ORDER BY timestamp ASC');
$stmt = $db->prepare('SELECT m.iduser, m.message, m.timestamp, u.name as user '.
    'FROM messages m LEFT JOIN dbu.users u ON u.id=m.iduser '.
    'WHERE idasset=:ida and m.id>=:id ORDER BY timestamp ASC');
$stmt->bindValue(':id', intval($_REQUEST['after']), SQLITE3_INTEGER);
$stmt->bindValue(':ida', intval($_REQUEST['idasset']), SQLITE3_INTEGER);
$results = $stmt->execute();

echo '[';
while ($row = $results->fetchArray(SQLITE3_ASSOC))
{
    echo json_encode($row).',';
}
echo '{}]';
$db->close();
?>
