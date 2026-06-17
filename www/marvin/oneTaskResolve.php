<?php
session_start();
if (!isset($_SESSION['id'])) {
    header('Content-Type: application/json');
    echo '{"status":"error","msg":"not authenticated"}';
    exit;
}
$myid = (int)$_SESSION['id'];
$taskId=-1;
if (isset($_REQUEST['idasset'])) $taskId=(int)$_REQUEST['idasset'];
else
{
    header('Location: tasks.php');
    die;
}
$action='';
if (isset($_REQUEST['action'])) $action=$_REQUEST['action'];
else
{
    header('Location: oneTask.php?idasset='.$taskId.'&message=no+action+defined');
	exit();
}

$txt='';
if (isset($_REQUEST['txt'])) $txt=$_REQUEST['txt'];
else
{
    header('Location: oneTask.php?idasset='.$taskId.'&message=no+txt+description+defined');
	exit();
}
header('Content-Type: application/json');

if (!in_array($action, ['accept', 'reject'])) {
    header('Location: oneTask.php?idasset='.$taskId.'&message=invalid+action');
    exit;
}

date_default_timezone_set('Europe/Brussels');
$db = new SQLite3('../../db/MarvinDB.sqlite', SQLITE3_OPEN_READWRITE);
$db->busyTimeout(5000);
$db->exec('PRAGMA journal_mode=WAL;');
$now = date('Ymd H:i:s');

// Load the task and verify it belongs to the current user
$task = $db->querySingle("SELECT * FROM Tasks WHERE id=$taskId and changeId is not NULL", true);

// todo: check the is of the assignedToUserId
//if (!$task || (int)$task['assignedToUserId'] !== $myid) || $task['completion'] > 0) 
if (!$task || $task['completion'] > 0) 
{
    $db->close();
    header('Location: oneTask.php?idasset='.$taskId.'&message=task+not+found+or+not+authorised');
    exit;
}

$tableName=$task['changeTable'];
$changeId=(int)$task['changeId'];
$changesTable = $tableName.'Changes';
//$rowId        = (int)$task['rowId'];

if ($action === 'accept') 
{
    // ── column-based tables: apply latest snapshot ───────────────────────────
    $r = $db->querySingle("SELECT * FROM $changesTable WHERE changeId=$changeId", true);
    if (!$r) {
        $db->close();
        header('Location: oneTask.php?idasset='.$taskId.'&message=no+changes+found');
        exit;
    }

//    foreach ($r as $key => $value) {
//        echo $key . "\n";
//    }

    $colCopyTableMap = [
        'Assets'   => ['schema_old','idserver_old','name_old','shortDescription_old',
            'longDescription_old','status_old','idowner_old','tags_old','category'],
//        'Columns'  => ['name_old','shortDescription_old','status_old','tags_old','idasset'],
        'Glossary' => ['shortDescription_old','longDescription_old','status_old','tags_old'],
//        'KPI'      => ['name_old','shortDescription_old','status_old','tags_old','idasset'],
        'servers'  => ['name_old','serverType_old','description_old','tags_old']
    ];
    $colOrigTableMap = [
        'Assets'   => ['schema','idserver','name','shortDescription',
            'longDescription','status','idowner','tags','category'],
//        'Columns'  => ['name','shortDescription','status','tags','idasset'],
        'Glossary' => ['shortDescription','longDescription','status','tags'],
//        'KPI'      => ['name','shortDescription','status','tags','idasset'],
        'servers'  => ['name','serverType','description','tags']
    ];

    $co=$colCopyTableMap[$tableName];
    $cn=$colOrigTableMap[$tableName];
    $len=count($co);
    $sql='UPDATE '.$tableName.' SET ';
    for($i=0;$i<$len;$i++)
    { 
        $n=$cn[$i];
        $ncontent=$r[$n];
        if (($ncontent!=null)&&($ncontent!=$r[$co[$i]]))
            $sql.=$n.'=:'.$n.',';
    }
    $sql = substr($sql, 0, -1);
    $sql.=' WHERE id=:id';
//    echo $sql;
//    die;
    $stmtApply = $db->prepare($sql);
    for($i=0;$i<$len;$i++)
    { 
        $n=$cn[$i];
        $ncontent=$r[$n];
        if (($ncontent!=null)&&($ncontent!=$r[$co[$i]]))
            $stmtApply->bindValue(':'.$n, $ncontent);
    }
    $stmtApply->bindValue(':id', $r['rowId']);
    if ($stmtApply->execute()===false)
    {
        $db->close();
        header('Location: oneTask.php?idasset='.$taskId.'&message=unable+to+apply+changes');
        exit;
    }

    $stmtTask = $db->prepare("UPDATE Tasks SET changeId=NULL, description=:txt, completion=2, dateFinished=:now WHERE id=:tid");
    $stmtTask->bindValue(':now', $now);
    $stmtTask->bindValue(':txt', $txt);
    $stmtTask->bindValue(':tid', $taskId);
    $stmtTask->execute();
} else 
{
    $stmtTask = $db->prepare("UPDATE Tasks SET changeId=NULL, description=:txt, completion=1, dateFinished=:now WHERE id=:tid");
    $stmtTask->bindValue(':now', $now);
    $stmtTask->bindValue(':txt', $txt);
    $stmtTask->bindValue(':tid', $taskId);
    $stmtTask->execute();
}

$stmtStatus = $db->prepare("DELETE FROM $changesTable WHERE changeId=:tid");
$stmtStatus->bindValue(':tid', $changeId);
$stmtStatus->execute();

if ($tableName=='Assets')
{
    $tt=(int)$task['taskType'];
    if (($tt==510)||($tt==610))
    {
        $stmtStatus = $db->prepare("DELETE FROM KPIChanges WHERE assetChangeId=:tid");
        $stmtStatus->bindValue(':tid', $changeId);
        $stmtStatus->execute();
    } else if (($tt==520)||($tt==620))
    {
        $stmtStatus = $db->prepare("DELETE FROM ColumnsChanges WHERE assetChangeId=:tid");
        $stmtStatus->bindValue(':tid', $changeId);
        $stmtStatus->execute();
    }
}

$db->close();
header('Location: oneTask.php?idasset='.$taskId.'&message=ok');

