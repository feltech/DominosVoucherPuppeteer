const puppeteer = require('puppeteer');
const moment = require('moment');
const express = require('express');
const _ = require('lodash');

let logger = require('logger').createLogger(),
	page,
	stopped = false;

logger.format = (level, date, message)=> {
	return moment(date).format("YYYY-MM-DD HH:mm:ss") + " | " + level.toUpperCase() + " |" + message;
};
logger.setLevel("debug");

function take10s() {
	let generated = [],
		alphabet = "ABCDEFGHIJKLMNOPQRSTUVWXYZ".split(""),
		i,j;

	for (i = 0; i < alphabet.length; i++) {
		for (j = 0; j < alphabet.length; j++) {
			generated.push({
				code: "TAKE10" + alphabet[i] + alphabet[j], description: "Generated TAKE10 code"
			});
		}
	}

	return generated;
}

async function initBrowser() {
	// Open browser.
	const browser = await puppeteer.launch({
		args: [
			'--no-sandbox',
			'--disable-setuid-sandbox'
		]
	});
	// Open new tab in browser.
	page = await browser.newPage();
	// Log site console logs.
	page.on('console', msg => logger.debug('PAGE LOG:', msg.text));
}

async function click(selector) {
	return page.evaluate((selector)=>{
		var evObj = document.createEvent('MouseEvents');
		evObj.initEvent("click", true, false);
		$(selector)[0].dispatchEvent(evObj);
	}, selector);
}

async function getVouchers(numPages) {
	let vouchers = [];
	
	logger.info("Loading hotukdeals");
	await page.goto("https://www.hotukdeals.com/vouchers/dominos.co.uk");			
	logger.info("Setting cookie to show vouchers and reloading page");
	await page.setCookie({name: "show_voucher", value: "true"});
	// Reload the page.
	await page.reload();
			
	for (let pageNum = 0; pageNum < numPages; pageNum++) {
		if (stopped)
			return;
		logger.info("On page " + (pageNum + 1));

		logger.debug("Waiting for vouchers to show");
		await page.waitForSelector("article.thread--voucher");
		// Extract voucher panel codes and descriptions.
		logger.debug("Extracting voucher data from page");
		let pageVouchers = await page.evaluate(()=> {
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
			logger.debug("Clicking for next page");
			await click("a[rel='next']");
			logger.debug("Waiting for navigation");
			await page.waitForNavigation();
		}
	}

	return [...take10s(), ...vouchers];
}

async function tryVouchers(postcode, vouchers) {
	logger.info("Loading Dominos");
	await page.goto("https://www.dominos.co.uk");			
	logger.debug("Waiting for store search input");
	await page.waitForSelector("#store-finder-search");
	logger.debug("Typing postcode");
	await page.type("#store-finder-search input[type='text']", postcode);
	logger.debug("Click to find store");
	await page.click("#btn-delivery");
	logger.debug("Waiting for menu button");
	await page.waitForSelector("#menu-selector");
	logger.debug("Clicking menu button");
	await page.click("#menu-selector");
	logger.debug("Waiting for menu to show");
	await page.waitForSelector("button[resource-name='AddToBasket']");
	logger.debug("Adding a pizza to the basket");
	await page.click("button[resource-name='AddToBasket']");
	logger.debug("Remove popup iframes");
	await page.evaluate(()=>$(".yie-holder").remove());
	logger.debug("Waiting for pizza to be added to basket");
	await page.waitFor(()=>$(".basket-item-count").text().trim() === "1");
	logger.debug("Clicking to view basket");
	await page.click("a.nav-link-basket");
	logger.debug("Dismiss popups");
	try {
		await page.waitForSelector(".esc", {visible: true, timeout: 1000});
		await page.click(".esc");
		await page.click("a.nav-link-basket");
	} catch (e) {
		logger.debug("No popups found")
	}
	logger.debug("Waiting for voucher code input to show");
	await page.waitForSelector("#voucher-code", {visible: true});
	
	logger.info("Entering codes");
	for (let voucher of vouchers) {
		if (stopped)
			return;
		logger.debug("Clearing any previous code");
		await page.$eval("#voucher-code", (el)=>el.value="");
		logger.debug("Typing code " + voucher.code);
		await page.type("#voucher-code", voucher.code, {delay: 10});
		logger.debug("Clicking to add code");
		await page.click("button.btn-add-voucher");
		logger.debug("Waiting until code applied");
		await page.waitForSelector("button.btn-add-voucher[disabled]", {hidden: true});
		logger.debug("Getting voucher success status");
		voucher.status = await page.evaluate(()=>{
			return $("div.voucher-code-input > p.help-block").text().trim();
		});
		voucher.valid =  !/invalid|expired|Voucher Used|already been used/i.test(voucher.status);
		// await page.screenshot({path: voucher.code + ".png", fullPage: true});
		if (voucher.valid) {
			logger.info("Voucher worked!");
			let voucherApplied = true;
			try {
				await page.waitForSelector(
					"[data-voucher] .basket-product-actions button", {timeout: 1000}
				);
			} catch (e) {
				logger.debug("Voucher valid but not applied");
				voucherApplied = false;
			}
			if (voucherApplied) {
				logger.debug("Clearing voucher");
				await page.click("[data-voucher] .basket-product-actions button");
				logger.debug("Waiting for confirmation modal");
				await page.waitForSelector('div.modal.in button[resource-name="OkButton"]');
				logger.debug("Confirming removal of voucher");
				await page.click('div.modal.in button[resource-name="OkButton"]');
				logger.debug("Waiting for voucher to clear");
				await page.waitForSelector(
					"[data-voucher] .basket-product-actions button", {hidden: true}
				);
			}		
		}
	}

	logger.debug("Final voucher state:\n" + JSON.stringify(vouchers));
	logger.info(
		"Working vouchers:" + vouchers.filter((v)=>v.valid).map(
			(v)=>"\n[" + v.code + "] " + v.description  
		)
	);
	return vouchers;
}

// Web app to respond to requests.
const app = express();

// Start the http server.
const server = app.listen(3000, () => logger.info("Dominos Voucher app listening on port 3000"));

// Express doesn't respond to signals without some help.
["SIGINT", "SIGTERM"].forEach((sigName)=>{
	process.on(sigName, ()=>{
		logger.info("Received " + sigName);
		stopped = true;
		server.close(()=> logger.info("HTTP server stopped."));
	});
});

initBrowser().then(async ()=> {
	try {
		let vouchers = await getVouchers(2);
		vouchers = await tryVouchers("CT27NY", vouchers);
		// await tryVouchers("CT27NY", [
		// 	{code: "TESTTEST", description: "broken"},
		// 	{code: "DOMIBETA", description: "test"}, {code: "TAKE10CT", description: "test2"}
		// ]);
	} catch (e) {
		logger.error("Error getting codes", e);
		await page.screenshot({path: "error.png", fullPage: true});
	}
});
