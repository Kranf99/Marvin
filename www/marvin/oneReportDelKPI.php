<?php
session_start();
if ( !isset( $_SESSION['id'] ) ) {
	header("Location: ./index.html");
    exit;
}
$params = $_REQUEST;
unset($params['idkpi']);
unset($params['edit']);

$myid= $_SESSION['id'];

// todo: validate that the user has the rights to delete kpi

$idkpi=-1;
if (isset($_REQUEST['idkpi'])) $idkpi=$_REQUEST['idkpi'];
else
{
	header('Location: oneReport.php?edit=1&'.http_build_query($params));
	exit();
}
$db = new SQLite3('../../db/MarvinDB.sqlite', SQLITE3_OPEN_READWRITE);
$stmt=$db->prepare('delete from KPI WHERE id = :p1');
$stmt->bindValue(':p1',$idkpi);
$stmt->execute();
$db->close();

header('Location: oneReport.php?edit=1&'.http_build_query($params));
?>