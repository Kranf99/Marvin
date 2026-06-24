<?php
session_start();
if ( !isset( $_SESSION['id'] ) ) {
	header("Location: ./index.html");
    exit;
}
$myid= $_SESSION['id'];

$ida=-1;
if (isset($_REQUEST['idasset'])) $ida=$_REQUEST['idasset'];
else
{
	header("Location: workflow.php");
	exit();
}
$db = new SQLite3('../../db/MarvinDB.sqlite', SQLITE3_OPEN_READWRITE);
$stmt=$db->prepare('delete from workflowIO WHERE idWorkflow = :p1');
$stmt->bindValue(':p1',$ida);
$stmt->execute();
$stmt=$db->prepare('delete from Assets WHERE id = :p1');
$stmt->bindValue(':p1',$ida);
$stmt->execute();
$db->close();
header("Location: workflow.php");
?>