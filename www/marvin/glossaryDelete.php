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
	header("Location: report.php");
	exit();
}
$db = new SQLite3('../../db/MarvinDB.sqlite', SQLITE3_OPEN_READWRITE);
$stmt=$db->prepare('update Glossary set toDelete=1 WHERE id = :p1');
$stmt->bindValue(':p1',$ida);
$stmt->execute();

require 'glossaryMakeFile.php';
$db->close();
header("Location: glossary.php");
?>