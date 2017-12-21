<?php
use \Psr\Http\Message\ServerRequestInterface as Request;
use \Psr\Http\Message\ResponseInterface as Response;

require 'vendor/autoload.php';

$config['displayErrorDetails'] = true;
// var_dump(phpinfo());

$app = new \Slim\App(["settings" => $config]);

// Dependency injector
$container = $app->getContainer();

// Database.
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

// Logger
$container['logger'] = function($c) {
    $logger = new \Monolog\Logger('my_logger');
    $handler = new \Monolog\Handler\ErrorLogHandler();
    $logger->pushHandler($handler);
    return $logger;
};

// Template renderer.
$container['view'] = new \Slim\Views\PhpRenderer("templates/");


/**
 * Get complete current voucher list.
 */
$app->get('/vouchers', function (Request $request, Response $response) {
	$vouchers = $this->db->table('vouchers')->select('*')->get();
	if ($vouchers === null) {
		$vouchers = [];
	}
	return $response->withJson($vouchers);
});

/**
 * Get local Dominos branch for given postcode.
 */
$app->get('/branch/{postcode}', function (Request $request, Response $response, $args) {
	// Check if vouchers haven't been updated in a day
	$postcode = cleanPostcode($args['postcode']);
	$row = $this->db->table('postcodes')->find($postcode, 'postcode');
	if ($row) {
		$response = $response->withJson($row);
	} else {
		$response = $response->withStatus(404);
	}
	return $response;
});

/**
 * Get currently known working vouchers for postcode.
 */
$app->get('/working/{postcode}', function (Request $request, Response $response, $args) {
	// Check if vouchers haven't been updated in a day
	$postcode = cleanPostcode($args['postcode']);

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

/**
 * Check for current state of postcode and bot, and fire off bot if updates are required.
 */
$app->get('/uptodate/{postcode}', function (Request $request, Response $response, $args) {
	// Check if vouchers haven't been updated in a day
	$postcode = cleanPostcode($args['postcode']);
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

	$branch = $this->db->table('postcodes')->join(
		'branches', 'branches.id', '=', 'postcodes.branch_id'
	)->select([
		'branches.last_updated', 'branches.last_closed'
	])->where('postcodes.postcode', $postcode)->first();

	$branch_uptodate = true;
	$branch_last_updated = null;
	$branch_last_closed = null;
	if ($branch === null) {
		// Never checked for vouchers for this postcode before.
		$branch_uptodate = false;
	} else {
		$branch_last_updated = (int)$branch->last_updated;
		$branch_last_closed = (int)$branch->last_closed;
		if (
			time() - $branch_last_updated > 24*3600 || // branch out of date.
			$vouchers_last_updated > $branch_last_updated  // vouchers updated more recently.
		) {
			$branch_uptodate = false;
		}
	}

	$bot_table = $this->db->table('bot_state');
	$bot_state = $bot_table->first();

	$updating = "none";

	if ($bot_state->busy_with !== null || $bot_state->error !== null) {
		$updating = "other";
	} else {
		if ($vouchers_uptodate === false) {
			$this->logger->debug("Voucher list out of date, notifying bot");
			$botresponse = Requests::get(getenv('bot_url') . "/vouchers");
			if ($botresponse->success) {
				$updating = "vouchers";
				$bot_table->update(['busy_with'=>$updating]);
			}
		} else if ($branch_last_updated === null) {
			$this->logger->debug("Branch for " . $postcode . " unknown, notifying bot");
			$botresponse = Requests::get(getenv('bot_url') . "/branch/" . $postcode);
			if ($botresponse->success) {
				$updating = "branch";
				$bot_table->update(['busy_with'=>$updating]);
			}
		} else if ($branch_uptodate == false) {
			$this->logger->debug(
				"Working vouchers for " . $postcode . " out of date, notifying bot"
			);
			$botresponse = Requests::get(getenv('bot_url') . "/working/" . $postcode);
			if ($botresponse->success) {
				$updating = "working";
				$bot_table->update(['busy_with'=>$updating]);
			}
		}
	}

	return $response->withJson([
		'vouchersLastUpdated'=>$vouchers_last_updated, 'branchLastUpdated'=>$branch_last_updated,
		'vouchersUpToDate'=>$vouchers_uptodate, 'branchUpToDate'=>$branch_uptodate,
		'branchLastClosed'=>$branch_last_closed, 'updating'=>$updating,
		'error'=>$bot_state->error !== null
	]);
});

$app->get('/ping', function (Request $request, Response $response) {
	$response->write("pong");
	return $response;
});

$app->get('/', function (Request $request, Response $response) {
	$response = $this->view->render($response, "main.phtml");
    return $response;
});


$app->group('/bot', function () use ($app) {

	/**
	 * Flag an error from the bot.
	 */
	$app->post('/error', function (Request $request, Response $response, $args) {
		$body = $request->getParsedBody();
		$this->db->table('bot_state')->update(['error'=>$body['error']]);
		return $response;
	});

	/**
	 * Update voucher list.
	 */
	$app->post('/vouchers', function (Request $request, Response $response) {
		$body = $request->getParsedBody();
		$this->logger->debug("Updating voucher list with ". count($body) . " vouchers");
		$this->db->transaction(function ($db) use ($body) {
			$db->table('vouchers')->delete();
			$db->table('vouchers')->insertIgnore($body);
			$db->table('vouchers_last_updated')->update(['last_updated'=>time()]);
		});
		return $response;
	});


	/**
	 * Update local Dominos branch for given postcode.
	 */
	$app->post('/branch', function (Request $request, Response $response) {
		$body = $request->getParsedBody();
		$this->logger->debug("Updating branch: ". json_encode($body));
		$this->db->transaction(function ($db) use ($body) {
			$timestamp = time();
			$db->table('branches')->insertIgnore(['id'=>$body['branch_id']]);
			$db->table('postcodes')->onDuplicateKeyUpdate([
				'branch_id'=>$body['branch_id'], 'last_updated'=>$timestamp
			])->insert([
				'postcode'=>$body['postcode'], 'branch_id'=>$body['branch_id'],
				'last_updated'=>$timestamp
			]);
		});
		$this->db->table('bot_state')->update(['busy_with'=>null]);
		return $response;
	});

	/**
	 * Signal that the Dominos branch is currently closed.
	 */
	$app->post('/closed', function (Request $request, Response $response) {
		$body = $request->getParsedBody();
		$this->logger->debug("Setting branch closed: ". json_encode($body));
		$db->table('branches')->where(
			'id', $body['branch_id']
		)->update([
			'last_closed'=>time()
		]);
		$this->db->table('bot_state')->update(['busy_with', null]);
		return $response;
	});

	/**
	 * Reset bot's busy state, called when bot wakes up.
	 */
	$app->post('/awake', function (Request $request, Response $response, $args) {
		return $response;
	});

	/**
	 * Update list of working vouchers for given branch.
	 */
	$app->post('/working', function (Request $request, Response $response) {
		$body = $request->getParsedBody();
// 		$this->logger->debug("Updating working vouchers: ". json_encode($body));
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

			$db->table('working')->insertIgnore($vouchers);
		});
		return $response;
	});

})->add(
	/**
	 * Middleware: reset bot's busy status.
	 */
	function ($request, $response, $next) {
		try {
			$response = $next($request, $response);
		} finally {
			$this->db->table('bot_state')->update(['busy_with'=>null]);
		}
		return $response->withStatus(204);
	}
)->add(
	/**
	 * Middleware: validate bot's JWT.
	 */
	new \Slim\Middleware\JwtAuthentication([
		"secret" => getenv("bot_secret"),
		"error" => function ($request, $response, $arguments) {
			$data["message"] = $arguments["message"];
			return $response->withJson($arguments);
		}
	])
);


function cleanPostcode($postcode) {
	return preg_replace("/\s/", "", strtoupper($postcode));
}

$app->run();
