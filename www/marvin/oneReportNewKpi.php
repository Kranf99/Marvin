<?php
session_start();
if ( !isset( $_SESSION['id'] ) ) {
	header("Location: ./index.html");
    exit;
}
$myid= $_SESSION['id'];

$params = $_REQUEST;
unset($params['edit']);

$idasset="";
if (isset($_REQUEST['idasset'])) $idasset=$_REQUEST['idasset'];
else
{
	header('Location: oneReport.php?edit=1&'.http_build_query($params));
	exit();
}
date_default_timezone_set('Europe/Brussels');

// todo: validate that the user has the rights to add kpi

$db = new SQLite3('../../db/MarvinDB.sqlite', SQLITE3_OPEN_READWRITE);
$stmt=$db->prepare('insert into KPI(idasset,name,status,popularity,rating,idowner,dateCreated,dateUpdated) '.
	' values (:ida,\'_new_KPI_\',0,0,0,:idowner,:mydate,:mydate)' );
$stmt->bindValue(':ida',$idasset);
$stmt->bindValue(':idowner',$myid);
$stmt->bindValue(':mydate',date('Ymd H:i:s'));
$stmt->execute();
$db->close();
header('Location: oneReport.php?edit=1&'.http_build_query($params));
?>