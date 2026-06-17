<?php require '_pe_checkSession.php'; ?>
<!DOCTYPE html>
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Marvin - Storage</title>
    <link rel="stylesheet" href="ressources/style.css">
    <link rel="icon" type="image/x-icon" href="/favicon.ico">
<?php require "_pe_headerScripts.php"; ?>
</head>
<body>
<?php 
require "_pe_starter.php";
?>
<!-- Content -->
<div class="content">
    <div class="main-section">
        <div >
            <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
            <!-- <h1 class="page-title">💾 Data Sources</h1> -->
            <h1 style="font-size: 34px; color: #333">
<?php
$idAsset=-1;
if (isset($_REQUEST['idasset'])) $idAsset=$_REQUEST['idasset'];
else
{
    echo 'Error: No Asset specified.';
    die;
}
$newAsset=0;
if (isset($_REQUEST['newAsset'])) $newAsset=$_REQUEST['newAsset'];

date_default_timezone_set('Europe/Brussels');
$db = new SQLite3('../../db/MarvinDB.sqlite', SQLITE3_OPEN_READONLY);
$db->exec("attach database '".__DIR__ . "/../../db/MarvinUsers.sqlite' as dbu;");
$stmt = $db->prepare('select a.*, u.name as ownername, u.email as owneremail from Glossary a'.
        ' LEFT JOIN Users u ON a.idowner=u.id where a.id=:ids');
$stmt->bindValue(':ids',$idAsset);
$results=$stmt->execute();
$rowAsset=$results->fetchArray(SQLITE3_ASSOC);
if (!$rowAsset)
{
    echo 'Error: Asset '.$idAsset.' not found';
    die;
}

// Check for a pending/approved change by the current user (shown immediately to the editor)
$pendingChange  = null;
$rejectedChange = null;
$stmtPC = $db->prepare(
    "SELECT * FROM GlossaryChanges WHERE rowId=:id AND changedByUserId=:uid AND changeStatus IN ('pending','approved') ORDER BY changeId DESC LIMIT 1"
);
$stmtPC->bindValue(':id',  $idAsset);
$stmtPC->bindValue(':uid', $myid);
$pendingChange = $stmtPC->execute()->fetchArray(SQLITE3_ASSOC);

if (!$pendingChange) {
    // Check if the most recent change was rejected (show banner only)
    $stmtRC = $db->prepare(
        "SELECT * FROM GlossaryChanges WHERE rowId=:id AND changedByUserId=:uid AND changeStatus='rejected' ORDER BY changeId DESC LIMIT 1"
    );
    $stmtRC->bindValue(':id',  $idAsset);
    $stmtRC->bindValue(':uid', $myid);
    $rejectedChange = $stmtRC->execute()->fetchArray(SQLITE3_ASSOC);
}

// The editor sees their own pending values; everyone else sees the committed row
$displayRow = $pendingChange ?: $rowAsset;

echo' DEFINITION ';
if (!$newAsset) echo htmlspecialchars($displayRow['name']);
else echo '<div style="display:inline" class="editable" data-columnname2="name" data-tablename="Glossary" data-id="'.
    $idAsset.'">'.$displayRow['name'].'</div>';
?></h1>
<?php if ($pendingChange): ?>
<div style="background:#e8f4fd;border:1px solid #5aace4;border-radius:6px;padding:8px 14px;margin-bottom:10px;font-size:13px;">
    &#9998; Your changes are <strong>pending review</strong> by the owner and are only visible to you.
</div>
<?php elseif ($rejectedChange): ?>
<div style="background:#fdecea;border:1px solid #e57373;border-radius:6px;padding:8px 14px;margin-bottom:10px;font-size:13px;">
    &#10007; Your previous changes were <strong>rejected</strong> by the owner.
</div>
<?php endif; ?>
<button id="enableDisableButton" class="server-hide-btn" onclick="enableDisableEdit(this)">Enable Edit</button>
</div>
    <div class="breadcrumb"><a href="home.php">Home</a> / <a href="glossary.php">Glossary</a>
<?php
echo '/ '.$rowAsset['name']
?>                        
    </div>
</div>
<div style='overflow-x: auto;'>
<div class="edit-panel">
    <div class="edit-field">
        <label>Short Description</label>
        <div class="text-input-style">
<?php
echo '<div class="editable" data-columnname="shortDescription" data-tablename="Glossary" data-id="'.
    $idAsset.'">'.$displayRow['shortDescription'].'</div>';
?>            
        </div>
    </div>
    <div class="edit-field">
        <label>Tags</label>
        <div class="text-input-style">
<?php
echo '<div class="editable" data-columnname2="tags" data-tablename="Glossary" data-id="'.
    $idAsset.'">'.$displayRow['tags'].'</div>';
?>
        </div>
    </div>
    <div>
        <div class="status-row">
            <label class="status-label">Status</label>
<?php
echo '<div class="status-buttons" data-columnname="status" data-tablename="Glossary" data-id="'.
    $idAsset.'">';
?>            
                <input type="radio" name="status" id="status-uncertified" value="uncertified" class="toxradio" onclick="saveContent(0,0,this.parentElement);"
<?php if ($displayRow['status']==0) echo 'checked'; ?>
                >
                <label for="status-uncertified" class="status-btn status-uncertified">Uncertified</label>
                <input type="radio" name="status" id="status-certified" value="certified" class="toxradio" onclick="saveContent(1,0,this.parentElement);"
<?php if ($displayRow['status']==1) echo 'checked'; ?>
                >
                <label for="status-certified" class="status-btn status-certified">Certified</label>
                <input type="radio" name="status" id="status-do-not-use" value="do-not-use" class="toxradio" onclick="saveContent(2,0,this.parentElement);"
<?php if ($displayRow['status']==2) echo 'checked'; ?>
                > 
                <label for="status-do-not-use" class="status-btn status-do-not-use">Do Not Use</label> 
            </div>
        </div>
    </div>
            
    <div class="edit-field edit-field--stacked">
        <label>Long Description</label>
<?php
echo '<textarea id="editorMain" rows="1" data-columnname="longDescription" data-tablename="Glossary" data-id="'.
    $idAsset.'">'.$displayRow['longDescription'].'</textarea></div>';
if ($myid!=$rowAsset['idowner'])
{ 
?>
    <div class="status-buttons">
        <input type="button" name="status" id="status-request-access" value="request-access" class="status-btn status-request-access" 
        style="min-width: 120px;padding: 10px 16px;border-radius: 6px;font-size: 13px;font-weight: 500;cursor: pointer;border: 2px solid transparent;"/> 
    </div>
<?php
}
echo '<div id="extradata" style="display:flex;flex-wrap:wrap;gap:10px;margin:14px 0 6px;">';
echo '<div class="extradata">&#128100; <em>Owner</em>: '.$rowAsset['ownername'].' (id: '.$rowAsset['idowner'].' ; email: '.$rowAsset['owneremail'].
     ') </div><div class="extradata">&#128336; <em>Last update</em>: '.
     $rowAsset['dateUpdated'].'</div><div class="extradata">&#9889; <em>Creation date</em>: '.$rowAsset['dateCreated'].'</div>';
?>
</div></div></div></div>
<!-- Right sidebar -->
<div class="sidebar-right">

<!-- Tab links -->
<div class="tab">
  <button class="tablinks" onclick="openTab(event,'ChatTab')" id="defaultTab">Conversation</button>
  <button class="tablinks" onclick="openTab(event,'ActivityTab')">Activity</button>
</div>

<!-- Tab content -->
<div id="ChatTab" class="tabcontent">
  <div class="mainchat">
    <div class="chat-container">
        <div class="chat-messages" id="messages"></div>
        <div class="chat-input-container">
            <input type="text" class="chat-input" id="messageInput" placeholder="Type a message..." />
            <button class="chatsend-button" id="sendButton">&#10148;</button>
        </div>
    </div>
</div>
</div>

<div id="ActivityTab" class="tabcontent" style="padding: 6px 6px;">


                        <h3>Recent Activity from others</h3>
<?php
//$db->exec("attach database '".__DIR__ . "/../../db/MarvinUsers.sqlite' as dbu;"); // already done above
$results = $db->query("SELECT a.*, u.name as username, u.imagefile as ifile from Activities a LEFT JOIN dbu.Users u ON u.id=a.userid where userid<>".$myid);

while(1)
{
	$row=$results->fetchArray(SQLITE3_ASSOC);
	if (!$row) break;
    echo '<div class="activity-item"><img src="'.defaultAvatarImage($row["ifile"]).
        '" class="activity-avatar"/><div class="activity-content"><div class="activity-title">'.
        $row['description'].'</div><div class="activity-subtitle">'.
        $row['username'].' edited '.$row['name'].
        '</div><div class="activity-time">'.getHumanElapsedTime($row['timestamp']).
        '</div></div></div>'."\n";
}
$results->finalize();
$db->close();
?>
</div>
                    </div>
                </div>
            </div>
    </div>
<?php require '_pe_footer.php'; ?>
<script>
initHugeRTEEditMain();
<?php
if ((isset($_REQUEST['edit']))||($newAsset))
{
  echo 'enableDisableEdit(document.getElementById("enableDisableButton"));';
}
?>
</script>
</body>