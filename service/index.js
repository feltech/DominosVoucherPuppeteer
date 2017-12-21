const puppeteer = require('puppeteer');
const moment = require('moment');
const express = require('express');
const _ = require('lodash');
const request = require('request-promise-native');
const nJwt = require('njwt');

const VOUCHER_PAGES = 3;
const INCLUDE_TAKE10S = false;

let logger = require('logger').createLogger();
logger.format = (level, date, message)=> {
	return moment(date).format("YYYY-MM-DD HH:mm:ss") + " | " + level.toUpperCase() + " |" + message;
};
logger.setLevel("debug");


class StoreClosedError extends Error {}


class Scraper {
	constructor() {
		this.sitePostOpts = {
			method: 'POST', resolveWithFullResponse: true, strictSSL: false, json: true,
			auth: {bearer: nJwt.create({}, process.env.bot_secret)}
		};
		this.siteGetOpts = {strictSSL: false, json: true};
		this.inProgress = false;

		// Web app to respond to requests.
		const app = express();
		app.use(function(req, res, next) {
			res.header("Access-Control-Allow-Origin", process.env.site_host);
			res.header(
				"Access-Control-Allow-Headers", "Origin, X-Requested-With, Content-Type, Accept"
			);
			next();
		});

		app.get("/branch/:postcode", (req, res)=>{
			let postcode = req.params.postcode.toUpperCase();
			logger.info("Updating branch ID for postcode " + postcode);
			this.processIfNotInProgress(res, ()=>this.branchID(postcode));
		});

		app.get("/working/:postcode", (req, res)=>{
			let postcode = req.params.postcode.toUpperCase();
			logger.info("Updating working vouchers for postcode " + postcode);
			this.processIfNotInProgress(res, ()=>this.workingVouchers(postcode, this.vouchers));
		});

		app.get("/vouchers", (req, res)=>{
			logger.info("Updating voucher list");
			this.processIfNotInProgress(res, ()=>this.getVouchers(VOUCHER_PAGES));
		});

		// Start the http server.
		const server = app.listen(
			3000, () => logger.info("Dominos Voucher app listening on port 3000")
		);

		// Express doesn't respond to signals without some help.
		["SIGINT", "SIGTERM"].forEach((sigName)=>{
			logger.debug("Adding signal handler for " + sigName);
			process.on(sigName, ()=>{
				logger.info("Received " + sigName);
				this.stopped = true;
				server.close(()=> logger.info("HTTP server stopped."));
			});
		});

		let siteVoucherUrl = "https://" + process.env.site_host + "/vouchers";
		logger.info("First run: getting vouchers from site at " + siteVoucherUrl);
		request(siteVoucherUrl, this.siteGetOpts).then(
			(vouchers)=>{
				this.vouchers = vouchers;
				this.inProgress = false;
				logger.info("Initialised with " + this.vouchers.length + " vouchers");
				logger.debug("Initial voucher list: ", this.vouchers)
			},
			(e)=>logger.error("Failed to update vouchers from site", e)
		);

		logger.debug("Clearing any previous error state");
		this.postToSite("awake");
	}

	/**
	 * Start function or respond with error if a function is already running.
	 *
	 * @param {Response} res express response object.
	 * @param {Function} fn function to execute.
	 */
	processIfNotInProgress(res, fn) {
		if (this.inProgress) {
			logger.debug("Already running, aborting.")
			res.status(400).send("Already processing");
		} else {
			this.inProgress = true;
			fn();
			res.status(204).end();
		}
	}

	/**
	 * Scrape voucher codes from hotukdeals.
	 *
	 * @param {Integer} numPages number of this.pages of vouchers to scrape.
	 */
	async getVouchers(numPages) {
		await this.initBrowser();
		try {
			let vouchers = [],
				siteResponse;

			logger.info("Loading hotukdeals");
			await this.page.goto(
				"https://www.hotukdeals.com/vouchers/dominos.co.uk", {waitUntil: "domcontentloaded"}
			);
			logger.info("Setting cookie to show vouchers and reloading page");
			await this.page.setCookie({name: "show_voucher", value: "true"});
			// Reload the this.page.
			await this.page.reload({waitUntil: "domcontentloaded"});

			for (let pageNum = 0; pageNum < numPages; pageNum++) {
				if (this.stopped)
					return;
				logger.info("On this.page " + (pageNum + 1));

				logger.debug("Waiting for vouchers to show");
				await this.page.waitForSelector("article.thread--voucher");
				// Extract voucher panel codes and descriptions.
				logger.debug("Extracting voucher data from page");
				let pageVouchers = await this.page.evaluate(()=> {
					let $threads = $("article.thread--voucher"),
						vouchers = [];
					for (let thread of $threads) {
						let $thread = $(thread);
						vouchers.push({
							description: $thread.find("strong.thread-title").text().trim(),
							codeTxt: $thread.find("div.voucher.clickable").text().trim()
						});
					}
					return vouchers;
				});

				logger.debug("Cleaning voucher data");
				pageVouchers = pageVouchers.reduce((vouchers, voucher)=>{
					let extracted = [],
						description = voucher.description;
					// Sometimes there are multiple codes listed in a single voucher section.
					for (let code of voucher.codeTxt.split(" ")) {
						// Dominos codes are all 8 characters long.
						if (code.length === 8) {
							extracted.push({code, description});
						}
					}
					return [...vouchers, ...extracted];
				}, []);

				vouchers.push(...pageVouchers);

				if (pageNum < numPages - 1) {
					logger.debug("Clicking for next this.page");
					await this.click("a[rel='next']");
					logger.debug("Waiting for navigation");
					await this.page.waitForNavigation({waitUntil: "domcontentloaded"});
				}
			}

			logger.debug("Found vouchers" + JSON.stringify(vouchers));

			if (INCLUDE_TAKE10S)
				vouchers = [...Scraper.take10s(), ...vouchers];

			this.vouchers = vouchers;

		} catch (e) {
			this.logError(e);
			this.inProgress = false;
			return;
		} finally {
			this.page = null;
		}

		await this.postToSite("vouchers", this.vouchers);
	}

	/**
	 * Get local Dominos branch.
	 *
	 * @param {String} postcode postcode to find local dominos branch.
	 */
	async branchID(postcode) {
		let branch_id;
		await this.initBrowser();
		try {
			branch_id = await this.loadBranch(postcode);
		} catch (e) {
			this.logError(e);
			this.inProgress = false;
			return;
		} finally {
			this.page = null;
		}

		await this.postToSite("branch", {branch_id, postcode});
	}

	/**
	 * Attempt each voucher code in turn on a test order at the Dominos UK website.
	 *
	 * @param {String} postcode postcode to find local dominos store to check.
	 * @param {Array[Object]} vouchers array of voucher objects.
	 */
	async workingVouchers(postcode, vouchers) {
		let branch_id;
		await this.initBrowser();
		try {
			branch_id = await this.loadBranch(postcode);

			if (await this.page.$(".store-finder-alert", {visible: true, timeout: 1000}))
				throw new StoreClosedError();

			if (await this.page.$("[data-store-id]")) {
				logger.debug("Clicking to select first branch in list");
				await this.page.click("article.store-details button.btn-primary");
			}

			logger.debug("Waiting for menu button");
			await this.page.waitForSelector("#menu-selector");
			logger.debug("Clicking menu button");
			await this.page.click("#menu-selector");
			logger.debug("Waiting for menu to show");
			await this.page.waitForSelector("button[resource-name='AddToBasket']");
			logger.debug("Dismiss popup");
			try {
				await this.page.waitForSelector("i.arrival-close", {visible: true, timeout: 1000});
				await this.page.click("i.arrival-close");
			} catch (e) {
				logger.debug("No popup found")
			}
			logger.debug("Remove popup iframes");
			await this.page.evaluate(()=>$(".yie-holder").remove());
			logger.debug("Adding a pizza to the basket");
			await this.page.click("button[resource-name='AddToBasket']");
			logger.debug("Waiting for pizza to be added to basket");
			await this.page.waitFor(()=>$(".basket-item-count").text().trim() === "1");
			logger.debug("Clicking to view basket");
			await this.page.click("a.nav-link-basket");
			logger.debug("Waiting for voucher code input to be visible");
			await this.page.waitForSelector(".voucher-code-input > form > input", {visible: true});
			logger.debug("Waiting for button to be visible");
			await this.page.waitForSelector("footer > button", {visible: true});

			logger.info("Entering codes");
			for (let voucher of vouchers) {
				if (this.stopped)
					return;
				logger.debug("Clearing any previous code");
				await this.page.$eval(".voucher-code-input > form > input", (el)=>el.value="");
				logger.debug("Typing code " + voucher.code);
				await this.page.type(".voucher-code-input > form > input", voucher.code, {delay: 20});
				logger.debug("Clicking to add code");
				await this.page.click("footer > button");
				logger.debug("Waiting until code applied");
				await this.page.waitForSelector("footer > button[disabled]", {hidden: true});
				logger.debug("Checking for voucher choice modal");
				
				try {
					await this.page.waitForSelector(
						'div.voucher-choice', {timeout: 10, visible: true}
					);
					logger.debug("Clicking voucher choice modal");
					await this.page.click('div.voucher-choice button');
				} catch (e)	{
					logger.debug("No voucher choice modal visible");
				}
				logger.debug("Getting voucher success status");
				voucher.status = await this.page.evaluate(()=>{
					return $("div.voucher-code-input > p.help-block").text().trim();
				});
				voucher.valid = !/invalid|expired|Voucher Used|already been used/i.test(voucher.status);
				// await this.page.screenshot({path: voucher.code + ".png", fullthis.page: true});
				if (voucher.valid) {
					logger.info("Voucher worked!");
					let voucherApplied = true;
					try {
						await this.page.waitForSelector(
							"[data-voucher] .basket-product-actions button", {timeout: 1000}
						);
					} catch (e) {
						logger.debug("Voucher valid but not applied");
						voucherApplied = false;
					}
					if (voucherApplied) {
						logger.debug("Clearing voucher");
						await this.page.click("[data-voucher] .basket-product-actions button");
						logger.debug("Waiting for confirmation modal");
						await this.page.waitForSelector('div.modal.in button[resource-name="OkButton"]');
						logger.debug("Confirming removal of voucher");
						await this.page.click('div.modal.in button[resource-name="OkButton"]');
						logger.debug("Waiting for voucher to clear");
						await this.page.waitForSelector(
							"[data-voucher] .basket-product-actions button", {hidden: true}
						);
					}
				}
			}

			logger.debug("Final voucher state:\n" + JSON.stringify(vouchers));
			vouchers = vouchers.filter((v)=>v.valid);
			logger.info(
				"Working vouchers:" + vouchers.map((v)=>"\n[" + v.code + "] " + v.description)
			);
		} catch (e) {
			this.inProgress = false;
			if (e instanceof StoreClosedError) {
				await this.postToSite("closed", {branch_id});
				return;
			}				
			this.logError(e);
		} finally {
			this.page = null;
		}

		await this.postToSite("working", {branch_id, vouchers});
	}

	/**
	 * Load local Dominos branch.
	 *
	 * @param {String} postcode postcode to find local dominos branch.
	 */
	async loadBranch(postcode) {
		logger.info("Loading Dominos for postcode " + postcode);
		await this.page.goto("https://www.dominos.co.uk");
		logger.debug("Waiting for store search input");
		await this.page.waitForSelector("#store-finder-search");
		logger.debug("Typing postcode");
		await this.page.type("#store-finder-search input[type='text']", postcode);
		logger.debug("Click to find branch");
		await this.page.click("#btn-delivery");
		try {
			logger.debug("Waiting for menu button");
			await this.page.waitForSelector("#menu-selector", {timeout: 5000});
			logger.debug("Getting store ID from javascript")
			await this.page.waitFor(
				()=>window.initalStoreContext &&
					window.initalStoreContext.sessionContext.storeId
			);
			return await this.page.evaluate(
				()=>window.initalStoreContext.sessionContext.storeId
			);
		} catch (e) {
			logger.debug("Branch closed, hopefully");
			if (await this.page.$("[data-store-id]")) {
				logger.debug("Getting store ID from button");
				return await this.page.evaluate(()=>$("[data-store-id]").first().data().storeId);
			}
			throw e;
		}
	}

	/**
	 * Send data to main site to store in it's database.
	 *
	 * @param {String} endpoint REST url endpoint to post to
	 * @param {Object} data the data to post
	 */
	async postToSite(endpoint, data) {
		try {
			let url = "https://" + process.env.site_host + "/bot/" + endpoint,
				response;
			logger.debug("Posting to main site: " + url);
			response = await request(url, Object.assign({ body: data }, this.sitePostOpts));
			logger.info(
				"Updated " + endpoint + " on main site " + process.env.site_host + " with status " +
				response.statusCode
			);
		} finally {
			this.inProgress = false;
		}
	}

	/**
	 * Fire up the Chrome headless browser and open a blank this.page.
	 */
	async initBrowser() {
		// Only one request at once...
		if (this.page)
			throw new Error("Attempting to open more than one browser session");
		try {
			// Open browser.
			const browser = await puppeteer.launch({
				args: [
					'--no-sandbox',
					'--disable-setuid-sandbox'
				]
			});
			// Open new tab in browser.
			this.page = await browser.newPage();
			this.page.setViewport({width: 1280, height: 720});
			// Log site console logs.
			this.page.on('console', msg => logger.debug('Page LOG:', msg.text));
		} catch (e) {
			this.logError(e);
		}
	}

	/**
	 * Generate common TAKE10XX codes.
	 *
	 * Dominos often has TAKE10XX codes (where XX are alphabetic characters) that give 10 GBP off
	 * some value of order (commonly 20 or 30 GBP).
	 */
	static take10s() {
		let generated = [],
			alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ".split(""),
			i,j;

		for (i = 0; i < alphabet.length; i++) {
			for (j = 0; j < alphabet.length; j++) {
				generated.push({
					code: "TAKE10" + alphabet[i] + alphabet[j], description: "Mystery TAKE10 code"
				});
			}
		}

		return generated;
	}

	/**
	 * Alternative method of clicking on an element based on a javascript `MouseEvents`.
	 *
	 * Puppeteer's  `click` sometimes simply doesn't work.
	 *
	 * @param {String} selector CSS selector to click on.
	 */
	async click(selector) {
		return this.page.evaluate((selector)=>{
			var ev = document.createEvent('MouseEvents');
			ev.initEvent("click", true, false);
			document.querySelector(selector).dispatchEvent(ev);
		}, selector);
	}

	/**
	 * Take a screenshot and log the error.
	 *
	 * @param {Response} res express response object
	 * @param {Error} e exception to log
	 */
	async logError(e) {
		logger.error("Unexpected exception", e);
		if (this.page)
			await this.page.screenshot({path: "error.png", fullPage: true});

		await this.postToSite("error", {error: e.toString()});
	}
}

let scraper = new Scraper();
