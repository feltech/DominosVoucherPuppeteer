<?php
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

require 'vendor/autoload.php';

$config['displayErrorDetails'] = true;
// var_dump(phpinfo());

$app = new \Slim\App(["settings" => $config]);

// Dependency injector
$container = $app->getContainer();
$container['db'] = function ($container_) {
	$config = array(
		'driver'    => 'mysql', // Db driver
		'host'      => 'localhost',
		'database'  => getenv("db_name"),
		'username'  => getenv("db_user"),
		'password'  => getenv("db_pass"),
		'charset'   => 'utf8', // Optional
		'collation' => 'utf8_unicode_ci' // Optional
	);

	$connection = new \Pixie\Connection('mysql', $config);
	return new \Pixie\QueryBuilder\QueryBuilderHandler($connection);	
};
$container['logger'] = function($c) {
    $logger = new \Monolog\Logger('my_logger');
    $handler = new \Monolog\Handler\ErrorLogHandler();
    $logger->pushHandler($handler);
    return $logger;
};
// Template renderer.
$container['view'] = new \Slim\Views\PhpRenderer("templates/");

$app->get('/vouchers', function (Request $request, Response $response) {
	$vouchers = $this->db->table('vouchers')->select('*')->get();	
	if ($vouchers === null) {
		$vouchers = [];	
	}	
	return $response->withJson($vouchers);
});

$app->post('/vouchers', function (Request $request, Response $response) {
	$body = $request->getParsedBody();
	$this->logger->debug("Updating voucher list with ". count($body) . " vouchers");
	$this->db->transaction(function ($db) use ($body) {
		$db->table('vouchers')->delete();
		$db->table('vouchers')->insert($body);
		$db->table('vouchers_last_updated')->update(['last_updated'=>time()]);
	});
	return $response;
});

$app->get('/branch/{postcode}', function (Request $request, Response $response, $args) {
	// Check if vouchers haven't been updated in a day
	$postcode = strtoupper($args['postcode']);
	$row = $this->db->table('postcodes')->find($postcode, 'postcode');
	if ($row) {
		$response = $response->withJson($row);
	} else {
		$response = $response->withStatus(404);
	}
	return $response;
});

$app->post('/branch', function (Request $request, Response $response) {
	// var_dump($request->getParsedBody());
	$body = $request->getParsedBody();
	$this->logger->debug("Updating branch: ". json_encode($body));
	$this->db->transaction(function ($db) use ($body) {
		$timestamp = time();
		$db->table('branches')->insertIgnore([
			'id'=>$body['branch_id'], 'last_updated'=> 0
		]);	
		$db->table('postcodes')->onDuplicateKeyUpdate([
			'branch_id'=>$body['branch_id'], 'last_updated'=>$timestamp
		])->insert([
			'postcode'=>$body['postcode'], 'branch_id'=>$body['branch_id'], 
			'last_updated'=>$timestamp
		]);
	});
	return $response->withStatus(204);
});

$app->get('/working/{postcode}', function (Request $request, Response $response, $args) {
	// Check if vouchers haven't been updated in a day
	$postcode = strtoupper($args['postcode']);

	$vouchers_updated = $this->db->table(
		'vouchers_last_updated'
	)->select('*')->first()->last_updated;
	
	$rows = $this->db->table('postcodes')->join(
		'branches', 'branches.id', '=', 'postcodes.branch_id'
	)->join(
		'working', 'working.branch_id', '=', 'branches.id'
	)->join(
		'vouchers', 'vouchers.code', '=', 'working.code'
	)->select([
		'vouchers.code'=>'code', 'vouchers.description'=>'description'
	])->where('postcodes.postcode', $postcode)->get();
	
	if ($rows === null) {
		$rows = [];
	}
	return $response->withJson($rows);
});

$app->post('/error', function (Request $request, Response $response, $args) {
	$body = $request->getParsedBody();
	$this->db->table('error')->update(['description'=>$body['description']]);
	return $response->withStatus(204);	
});

$app->get('/uptodate/{postcode}', function (Request $request, Response $response, $args) {
	// Check if vouchers haven't been updated in a day
	$postcode = strtoupper($args['postcode']);
	$timestamp = time();

	$vouchers_last_updated = $this->db->table(
		'vouchers_last_updated'
	)->select('*')->first();

	$vouchers_uptodate = true;
	if ($vouchers_last_updated === null) {
		$vouchers_uptodate = false;
	} else {
		$vouchers_last_updated = (int)$vouchers_last_updated->last_updated;
		if (time() - $vouchers_last_updated > 24*3600) {
			$vouchers_uptodate = false;
		}
	}

	$branch_last_updated = $this->db->table('postcodes')->join(
		'branches', 'branches.id', '=', 'postcodes.branch_id'
	)->select([
		'branches.last_updated'
	])->where('postcodes.postcode', $postcode);

	$branch_last_updated = $branch_last_updated->first();

	$branch_uptodate = true;
	if ($branch_last_updated === null) {
		// Never checked for vouchers for this postcode before.
		$branch_uptodate = false;
	} else {
		$branch_last_updated = (int)$branch_last_updated->last_updated;
		if (
			time() - $branch_last_updated > 24*3600 || // branch out of date.
			$vouchers_last_updated > $branch_last_updated  // vouchers updated more recently.
		) {
			$branch_uptodate = false;
		}
	}

	$error = $this->db->table('error')->select('description')->first()->description === "";

	$updating = "other";
	if ($vouchers_uptodate == false) {
		$this->logger->debug("Voucher list out of date, notifying bot");
		$botresponse = Requests::get(getenv('bot_url') . "/vouchers");
		if ($botresponse->success) {
			$updating = "vouchers";
		}
	} else if ($branch_last_updated === null) {
		$this->logger->debug("Branch for " . $postcode . " unknown, notifying bot");
		$botresponse = Requests::get(getenv('bot_url') . "/branch/" . $postcode);
		if ($botresponse->success) {
			$updating = "branch";
		}
	} else if ($branch_uptodate == false) {
		$this->logger->debug("Working vouchers forr " . $postcode . " out of date, notifying bot");
		$botresponse = Requests::get(getenv('bot_url') . "/working/" . $postcode);
		if ($botresponse->success) {
			$updating = "working";
		}
	} else {
		$updating = "none";
	}

	return $response->withJson([
		'vouchersLastUpdated'=>$vouchers_last_updated, 'branchLastUpdated'=>$branch_last_updated, 
		'vouchersUpToDate'=>$vouchers_uptodate, 'branchUpToDate'=>$branch_uptodate,
		'updating'=>$updating, '$error'=>$error
	]);
});

$app->post('/working', function (Request $request, Response $response) {
	// var_dump($request->getParsedBody());
	$body = $request->getParsedBody();
	$branchID = $body['branch_id'];

	$this->logger->debug(
		"Updating working voucher list for " . $branchID . " with ". 
		count($body['vouchers']) . " vouchers"
	);
	
	$vouchers = array_map(function ($voucher) use ($branchID) {
		return ['code'=>$voucher['code'], 'branch_id'=>$branchID];
	}, $body['vouchers']);

	$this->db->transaction(function ($db) use ($branchID, $vouchers) {
		$timestamp = time();
		$db->table('branches')->where('id', '=', $branchID)->update(['last_updated'=>$timestamp]);

		$db->table('working')->where('branch_id', '=', $branchID)->delete();

		$db->table('working')->insert($vouchers);
	});
	return $response->withStatus(204);
});

$app->get('/ping', function (Request $request, Response $response) {
	$response->write("pong");
	return $response;
});

$app->get('/', function (Request $request, Response $response) {
	$response = $this->view->render($response, "main.phtml");
    return $response;
});

$app->run();
