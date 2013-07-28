<!DOCTYPE html>
<html>
    <head>
        <link href="//netdna.bootstrapcdn.com/twitter-bootstrap/2.3.2/css/bootstrap-combined.min.css" rel="stylesheet"/>
        <title></title>
    </head>
    <body>

<?php

include 'comments.class.php';


$db_details = array(
	'db_host' => 'localhost',
	'db_user' => 'root',
	'db_pass' => '',
	'db_name' => 'test'
	);


$settings = array('isAdmin' => true, 'public' => false); // that is all you need to specify to be in admin mode :D

$page_id = 1;

$comments = new Comments_System($db_details, $settings);

$comments->grabComment($page_id);

if($comments->success)
	echo "<div class='alert alert-success' id='comm_status'>".$comments->success."</div>";
else if($comments->error)
	echo "<div class='alert alert-error' id='comm_status'>".$comments->error."</div>";

// a simple form
echo $comments->generateForm();

// we show the posted comments
echo $comments->generateComments($page_id); // we pass the page id

?>
