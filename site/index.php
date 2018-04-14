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
    $logger = new \Monolog\Logger('index');
    $handler = new \Monolog\Handler\ErrorLogHandler();
    $logger->pushHandler($handler);
    return $logger;
};

// Template renderer.
$container['view'] = new \Slim\Views\PhpRenderer("templates/");

/**
 * Middleware: postcode cleaning and validating.
 */ 
$cleanPostcode = function (Request $request, Response $response, $next) {
	$routeParams = $request->getAttribute('routeInfo')[2];
	// error_log(json_encode($request->getAttributes(), JSON_UNESCAPED_SLASHES));
	if (!$routeParams['postcode'] ?? null)
		return $response->withStatus(400)->write("No postcode");

	$postcode = $routeParams['postcode'];
	$postcode = preg_replace("/\s/", "", strtoupper($postcode));
	// Modified from https://stackoverflow.com/questions/164979/uk-postcode-regex-comprehensive
	$regex = '/^([G][I][R]0[A]{2})|((([A-Z][0-9]{1,2})|(([A-Z][A-H-J-Y-][0-9]{1,2})|(([A-Z][0-9][A-Z])|([A-Z-][A-H-J-Y-][0-9]?[A-Z]))))[0-9][A-Z]{2})$/';
	if (!preg_match($regex, $postcode))
		return $response->withStatus(400)->write("Bad postcode: " . $postcode);

	$routeParams['postcode'] = $postcode;
	return $next($request, $response);
};

/**
 * Query the bot at given endpoint with optional postcode
 */
$queryBot = function ($endpoint, $postcode=null) use (&$app) {
	$container = $app->getContainer();
	$url = getenv('bot_url') . "/" . $endpoint;
	if ($postcode) {
		$url = $url . "/" . $postcode;
	}
	$botresponse = null;
	try {
		$botresponse = Requests::get($url, [], ['timeout'=>60]);
	} catch (Requests_Exception $e) {
		$container->logger->error(
			"Connection error requesting bot at '" . $url . "': " . $e->getMessage()
		);
		throw $e;
	}
	if ($botresponse->success) {
		$container->db->table('bot_state')->update(['busy_with'=>$endpoint]);
	} else {
		$container->logger->error(
			"Bot rejected request (" . $botresponse->status . "): " . $botresponse->body
		);
		throw new Requests_Exception("Bot rejected request", "bot", null, 503);
	}
};

/**
 * Get currently known working vouchers for postcode.
 */
$app->get('/working/{postcode}', function (Request $request, Response $response, $args) {
	// $this->logger->debug(json_encode(debug_backtrace(), JSON_UNESCAPED_SLASHES));
	$postcode = $args['postcode'];

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
})->add($cleanPostcode);

/**
 * Check for current state of postcode and bot, and fire off bot if updates are required.
 */
$app->get(
	'/uptodate/{postcode}', 
	function (Request $request, Response $response, $args) use ($queryBot) {
		$postcode = $args['postcode'];
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
			'branches.id', 'branches.last_updated', 'branches.last_closed'
		])->where('postcodes.postcode', $postcode)->first();

		$branch_uptodate = true;
		$branch_exists = null;
		$branch_last_updated = null;
		$branch_last_closed = null;
		if ($branch === null) {
			// Never checked for vouchers for this postcode before.
			$branch_uptodate = false;
		} else {
			$branch_last_updated = (int)$branch->last_updated;
			$branch_last_closed = (int)$branch->last_closed;
			$branch_exists = (int)$branch->id !== -1;
			if (
				time() - $branch_last_updated > 24*3600 || // branch out of date.
				$vouchers_last_updated > $branch_last_updated  // vouchers updated more recently.
			) {
				$branch_uptodate = false;
			}
		}

		$bot_state = $this->db->table('bot_state')->first();
		$error = $bot_state->error !== null;

		$updating = "none";

		if ($branch_exists !== false) {
			if ($bot_state->busy_with !== null || $bot_state->error !== null) {
				$updating = "other";
			} else {
				try {
					if ($vouchers_uptodate === false) {
						$this->logger->debug("Voucher list out of date, notifying bot");
						$updating = 'vouchers';
						$queryBot($updating);
					} else if ($branch_last_updated === null) {
						$this->logger->debug("Branch for " . $postcode . " unknown, notifying bot");
						$updating = 'branch';
						$queryBot($updating, $postcode);
					} else if ($branch_uptodate == false) {
						$this->logger->debug(
							"Working vouchers for " . $postcode . " out of date, notifying bot"
						);
						$updating = 'working';
						$queryBot($updating, $postcode);
					}
				} catch (Requests_Exception $e) {
					$error = true;
				}
			} // End if bot is not busy.
		} // End if branch exists.

		return $response->withJson([
			'vouchersLastUpdated'=>$vouchers_last_updated, 'branchLastUpdated'=>$branch_last_updated,
			'vouchersUpToDate'=>$vouchers_uptodate, 'branchUpToDate'=>$branch_uptodate,
			'branchLastClosed'=>$branch_last_closed, 'updating'=>$updating,
			'error'=>$error, 'branchExists'=>$branch_exists
		]);
	}
)->add($cleanPostcode);

/**
 * Render main index page.
 */
$app->get('/', function (Request $request, Response $response) {
	$response = $this->view->render($response, "main.phtml");
	return $response;
});

/**
 * Group bot-only endpoints.
 */
$app->group('/bot', function () use (&$app) {

	/**
	 * Flag an error from the bot.
	 */
	$app->post('/error', function (Request $request, Response $response) {
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
		$this->db->table('branches')->where(
			'id', $body['branch_id']
		)->update([
			'last_closed'=>time()
		]);
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

	/**
	 * Get complete current voucher list.
	 * 
	 * This is only run on instantation of the bot, so also reset error state.
	 */
	$app->get('/vouchers', function (Request $request, Response $response) {
		$vouchers = $this->db->table('vouchers')->select('*')->get();
		if ($vouchers === null) {
			$vouchers = [];
		}		
		$this->db->table('bot_state')->update(['error'=>null]);
		return $response->withJson($vouchers);
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
		if ($response->getBody()->getSize())
			return $response;
		return $response->withStatus(204);
	}
)->add(
	/**
	 * Middleware: validate bot's JWT.
	 */
	new \Slim\Middleware\JwtAuthentication([
		"secret" => getenv("bot_secret"),
		"error" => function ($request, $response, $arguments) {
			return $response->withJson(['message'=>$arguments["message"]]);
		}
	])
);

$app->run();
