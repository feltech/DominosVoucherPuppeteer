<?php 
header('Content-Type: text/html; charset=utf-8');

$db = new mysqli("localhost", "id3953329_feltech", "feltell", "id3953329_vouchers"); 
$result = $db->query("select * from codes");
?>


<!DOCTYPE HTML>
<html lang="en">
<head>
	<meta charset="UTF-8" />
	<meta http-equiv="X-UA-Compatible" content="IE=edge" />
	<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
	<meta name="viewport" content="width=device-width, initial-scale=1" />
	<link
		rel='stylesheet'
		href='https://maxcdn.bootstrapcdn.com/bootswatch/3.3.7/paper/bootstrap.min.css' />	
	<script src='https://maxcdn.bootstrapcdn.com/bootstrap/3.3.7/js/bootstrap.min.js'></script>		
	<title>Dominos UK Voucher Puppeteer</title>
</head>
<body>
<nav class="navbar navbar-default">
	<div class="container-fluid">
		<div class="navbar-header">
			<a class="navbar-brand" href="#">
				Dominos (UK) Voucher Puppeteer 
			</a>
		</div>
	</div>
</nav>
<div class="jumbotron">
	<div class="container">
		<h1>Coming soon</h1> 
		<p>working voucher codes for Dominos Pizza UK, searchable by postcode</p>
	</div>
</div>
<div class="container">
	<dl class="dl-horizontal">
<?php 
while ($row = $result->fetch_assoc()) {
?>	
		<dt><?php echo $row['code'] ?></dt>
		<dd><?php echo $row['description'] ?></dd>
<?php 
} 
?>
	</dl>
</div>
</body>
</html>
<?php
$db->close();
?>