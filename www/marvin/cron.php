<?php
file_put_contents(__DIR__ . '/avatar/cron_daemon.pid', getmypid());

function hasFieldChanges($r)
{
    if ($r['name']&&($r['name']!=$r['name_old'])) 
        return true;
    if ($r['shortDescription']&&($r['shortDescription']!=$r['shortDescription_old'])) 
        return true;
    if ($r['longDescription']&&($r['longDescription']!=$r['longDescription_old'])) 
        return true;
    if ($r['status']&&($r['status']!=$r['status_old'])) 
        return true;
    if ($r['tags']&&($r['tags']!=$r['tags_old'])) 
        return true;
    if (isset($r['schema'])&&$r['schema']&&($r['schema']!=$r['schema_old']))
        return true;
    if ($r['idserver']&&($r['idserver']!=$r['idserver_old'])) 
        return true;
    return false;
}

function hasWordChanged($r)
{
    if ($r['shortDescription']&&($r['shortDescription']!=$r['shortDescription_old']))
        return true;

    if ($r['longDescription']&&($r['longDescription']!=$r['longDescription_old']))
        return true;

    if ($r['status']&&($r['status']!=$r['status_old'])) 
        return true;

    if ($r['tags']&&($r['tags']!=$r['tags_old'])) 
        return true;
    return false;
}

function hasColumnChanges($r)
{
    if ($r['cname']&&($r['cname']!=$r['cname_old'])) 
        return true;
    if ($r['cshortDescription']&&($r['cshortDescription']!=$r['cshortDescription_old'])) 
        return true;
    if ($r['cstatus']&&($r['cstatus']!=$r['cstatus_old'])) 
        return true;
    if ($r['ctags']&&($r['ctags']!=$r['ctags_old'])) 
        return true;
    return false;
}

function createTask($db,$taskType,$tname,$rr,$now,$tableName)
{
//    $needCheck=(int)$rr['needCheck'];
    $aid=$rr['changeId'];
    // Create the ReviewTask (rowId stores subjectId for both schemas)
    $stmtTask = $db->prepare(
        ' INSERT INTO Tasks (taskType,Name,idasset,changeId,changeTable,assignedToUserId, '.
        ' requestedByUserId, status, rating, urgency, completion, dateCreated, dateUpdated)'.
        ' VALUES (:tasktype,:name,:idasset,:changeId,:ct,:assignedTo,:requestedBy,0,0,0,0,:dd,:dd)');
    $stmtTask->bindValue(':tasktype',    $taskType);
    $stmtTask->bindValue(':name',        $tname);
    $stmtTask->bindValue(':idasset',     $rr['rowId']);
    $stmtTask->bindValue(':changeId',    $aid);
    $stmtTask->bindValue(':ct',          $tableName);
    $stmtTask->bindValue(':assignedTo',  $rr['idowner_old']);
    $stmtTask->bindValue(':requestedBy', $rr['changedByUserId']);
    $stmtTask->bindValue(':dd',          $now);
    $stmtTask->execute();
    $taskId = (int)$db->lastInsertRowID();

    // Link changes to the new task
    $stmtLink = $db->prepare('UPDATE '.$tableName.'Changes SET taskId=:taskId WHERE changeId=:changeId');
    $stmtLink->bindValue(':taskId',$taskId);
    $stmtLink->bindValue(':changeId', $aid);
    $stmtLink->execute();
    return $taskId;
}

date_default_timezone_set('Europe/Brussels');
$now    = date('Ymd H:i:s');
echo "[$now] Cron Started\n";
$debug=1;

// CLEANING OLD RESOLVED TASKS (Assets older than 20 days)
{
    $db = new SQLite3(__DIR__ . '/../../db/MarvinDB.sqlite', SQLITE3_OPEN_READWRITE);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode=WAL;');
    $taskIds = [];
    $changeIds = [];

    $cleanCutoff = date('Ymd H:i:s', time() - 20 * 86400);
    $stmt = $db->prepare(
        'SELECT t.id, t.changeId FROM Tasks t'.
        ' LEFT JOIN AssetsChanges ac ON ac.changeId=t.changeId'.
        ' WHERE t.tasktype>600 AND ac.updatedAt<:cutoff');
    $stmt->bindValue(':cutoff', $cleanCutoff);
    $result = $stmt->execute();
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
        array_push($taskIds,(int)$row['id']);
        array_push($changeIds,(int)$row['changeId']);
    }
    $stmt->close();

    if (!empty($changeIds)) {
        $s = implode(',', $changeIds);
        $db->exec('DELETE FROM ColumnChanges WHERE assetChangeId IN ('.$s.')');
        $db->exec('DELETE FROM KPIChanges WHERE assetChangeId IN ('.$s.')');
        $db->exec('DELETE FROM AssetsChanges WHERE changeId IN ('.$s.')');
    }
    if (!empty($taskIds)) {
        $s = implode(',', $taskIds);
        $db->exec('DELETE FROM Tasks WHERE id IN ('.$s.')');
    }
    $db->close();
}

// Cron entry: */5 * * * * php /path/to/marvin/cron.php >> /var/log/marvin_cron.log 2>&1
// Not intended for web access.
for(;;)
{
    $db = new SQLite3(__DIR__ . '/../../db/MarvinDB.sqlite', SQLITE3_OPEN_READWRITE);
    $db->busyTimeout(5000);
    $db->exec('PRAGMA journal_mode=WAL;');
    $db->exec("attach database '" . __DIR__ . "/../../db/MarvinUsers.sqlite' as dbu;");

    $now    = date('Ymd H:i:s');
    if (!$debug) $cutoff = date('Ymd H:i:s', time() - 180); // changes older than 3 minutes
    else
    {
//    for debug:
        $cutoff = date('Ymd H:i:s', time() - 10); // changes older than 10 seconds
        echo "[$now] CutOff: $cutoff\n";
    }

    // find unprocessed changes inside ASSETSCHANGES older than 3 minutes
    $stmt = $db->prepare(
        ' SELECT a.*, c.changeId as cchangeId, c.name as cname,c.name_old as cname_old, '.
        ' c.shortDescription as cshortDescription, c.shortDescription_old as cshortDescription_old,'.
        ' c.status as cstatus, c.status_old as cstatus_old,'.
        ' c.tags as ctags, c.tags_old as ctags_old,'.
        ' u.name as uname FROM AssetsChanges a'.
        ' LEFT JOIN columnsChanges c ON c.assetChangeId=a.changeId'.
        ' LEFT JOIN dbu.users u on u.id=a.changedByUserId'.
        ' WHERE a.taskId IS NULL and a.updatedAt<:cutoff');
    $stmt->bindValue(':cutoff', $cutoff);
    $groups = $stmt->execute();
    $r = $groups->fetchArray(SQLITE3_ASSOC);
    $arrayID_r=[]; $arrayID_s=[]; $arrayID_w=[];

    while($r)
    {
        $rr=$r;
        $aid=(int)$rr['changeId'];
        $hasCChange=false;
        if ($rr['cchangeId']==null) $r=$groups->fetchArray(SQLITE3_ASSOC);
        else
        {
            while (($r)&&($aid==(int)$r['changeId']))
            {
                $hasCChange=$hasCChange||hasColumnChanges($r);
                $r=$groups->fetchArray(SQLITE3_ASSOC);
            }
        }

        if (hasFieldChanges($rr)||$hasCChange)
        {
            $needCheck= (int)$rr['needCheck'];

            if ($needCheck)
            {
                $tname='Validate changes from '.htmlspecialchars($rr['uname']).' on '.htmlspecialchars($rr['name_old']);
            } else
            {
                $tname='Notification: User '.htmlspecialchars($rr['uname']).' changed '.htmlspecialchars($rr['name_old']);
            }

            $taskId=createTask($db,$needCheck?520:620,$tname,$rr,$now,'Assets');
            echo "[$now] Created task #$taskId \n";
        } else
        {
            $c=$rr['category'];
//            echo" delete $c";
            if ($c<100) $arrayID_r[]=$aid;
            else if ($c<200) $arrayID_s[]=$aid;
            else $arrayID_w[]=$aid;
//            else array_push($arrayID2,$aid);
        }
    }
    $stmt->close();

    if ((!empty($arrayID_r))||(!empty($arrayID_s))||(!empty($arrayID_w)))
    {
        $s2 = implode(',', $arrayID_r);
        if ($s2!='') $db->exec('DELETE FROM KPIChanges WHERE assetChangeId IN ('.$s2.')');
        $s = implode(',', $arrayID_s);
        if ($s!='') $db->exec('DELETE FROM ColumnChanges WHERE assetChangeId IN ('.$s.')');
        $s3 = implode(',', $arrayID_w);

        if ($s2!='') { if ($s!='') $s.=','.$s2; else $s=$s2; }
        if ($s3!='') { if ($s!='') $s.=','.$s3; else $s=$s3; }
        $s='DELETE FROM AssetsChanges WHERE changeId IN ('.$s.')';
        echo $s;
        $db->exec($s);
    }
    $s=''; $s2=''; $s3='';
    $arrayID_r=[]; $arrayID_s=[]; $arrayID_w=[];

    // find unprocessed changes inside GLOSSARY older than 3 minutes

    $stmt = $db->prepare(
        ' SELECT a.*, u.name as uname FROM GlossaryChanges a'.
        ' LEFT JOIN dbu.users u on u.id=a.changedByUserId'.
        ' WHERE a.taskId IS NULL AND a.updatedAt<:cutoff');
    $stmt->bindValue(':cutoff', $cutoff);
    $groups = $stmt->execute();
    $r = $groups->fetchArray(SQLITE3_ASSOC); 
    while($r)
    {
        if (hasWordChanged($r))
        {
            $needCheck= (int)$r['needCheck'];
             if ($needCheck)
            {
                $tname='Validate changes from '.htmlspecialchars($r['uname']).' on '.htmlspecialchars($r['name_old']);
            } else
            {
                $tname='Notification: User '.htmlspecialchars($r['uname']).' changed '.htmlspecialchars($r['name_old']);
            }
            $taskId=createTask($db,$needCheck?540:640,$tname,$r,$now,'Glossary');
            echo "[$now] Created task #$taskId \n";
        } else
        {
            array_push($arrayID,$r['changeId']);
        }
        $r=$groups->fetchArray(SQLITE3_ASSOC);
    }
    if (!empty($arrayID)) 
    {
        $s = implode(',', $arrayID);
        $db->exec("DELETE FROM GlossaryChanges WHERE changeId IN ($s)");
        $arrayID=[];
    }
    $stmt->close();

    $v1=$db->querySingle("Select changeId from AssetsChanges where taskId IS NULL limit 1");
    $v2=$db->querySingle("Select changeId from GlossaryChanges where taskId IS NULL limit 1");
    $v3=$db->querySingle("Select changeId from serversChanges where taskId IS NULL limit 1");
    $db->close();
    if (($v1==null)&&($v2==null)&&($v3==null))
    {
        if ($debug) echo "[$now] About to close\n";
        sleep(30);
        $db = new SQLite3(__DIR__ . '/../../db/MarvinDB.sqlite', SQLITE3_OPEN_READONLY);
        $v1=$db->querySingle("Select changeId from AssetsChanges where taskId IS NULL limit 1");
        $v2=$db->querySingle("Select changeId from GlossaryChanges where taskId IS NULL limit 1");
        $v3=$db->querySingle("Select changeId from serversChanges where taskId IS NULL limit 1");
        $db->close();
        if (($v1==null)&&($v2==null)&&($v3==null))
            break;
    } else 
    {
        if (!$debug) sleep(60);
        else sleep(10);
    }
}
unlink( __DIR__ . '/avatar/cron_daemon.pid');
$now    = date('Ymd H:i:s');
echo "[$now] Cron complete\n";
