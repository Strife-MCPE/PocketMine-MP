<?php

/*
 *
 *  ____            _        _   __  __ _                  __  __ ____
 * |  _ \ ___   ___| | _____| |_|  \/  (_)_ __   ___      |  \/  |  _ \
 * | |_) / _ \ / __| |/ / _ \ __| |\/| | | '_ \ / _ \_____| |\/| | |_) |
 * |  __/ (_) | (__|   <  __/ |_| |  | | | | | |  __/_____| |  | |  __/
 * |_|   \___/ \___|_|\_\___|\__|_|  |_|_|_| |_|\___|     |_|  |_|_|
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Lesser General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 *
 * @author PocketMine Team
 * @link http://www.pocketmine.net/
 *
 *
 */

declare(strict_types=1);

namespace pocketmine\utils;

use function date;
use function get_class;
use function getenv;
use function gethostname;
use function implode;
use function json_encode;
use function strlen;
use function substr;
use function time;
use function trim;
use const DATE_ATOM;

/**
 * Strife patch: posts unhandled exceptions to a Discord webhook, so crashes
 * still get noticed now that the server stays alive instead of shutting down
 * when one occurs (see Server::crashDump() / Server::tickProcessor()).
 *
 * Configured entirely via environment variables (Docker-friendly):
 * - DISCORD_CRASH_WEBHOOK: the webhook URL; unset or empty disables reporting
 * - STRIFE_SERVER_NAME: name shown in messages (defaults to the hostname)
 */
final class DiscordCrashNotifier{
	/** Identical crash sites are reported at most once per this many seconds */
	private const SITE_COOLDOWN_SECONDS = 60;
	/** Global floor between webhook posts - protects the tick loop (the HTTP call blocks) and the webhook rate limit */
	private const MIN_SEND_INTERVAL_SECONDS = 5;
	private const HTTP_TIMEOUT_SECONDS = 5;
	private const MAX_DESCRIPTION_LENGTH = 3500; //Discord embed description limit is 4096, leave headroom for markup
	private const EMBED_COLOR_RED = 0xed4245;

	/** @var array<string, int> crash site => last notification unix time */
	private static array $lastNotifiedSite = [];
	private static int $lastSendTime = 0;

	private function __construct(){
		//NOOP
	}

	public static function notifyException(\Throwable $e, ?string $context = null) : void{
		try{
			$site = get_class($e) . "@" . $e->getFile() . ":" . $e->getLine();
			$now = time();
			if(isset(self::$lastNotifiedSite[$site]) && $now - self::$lastNotifiedSite[$site] < self::SITE_COOLDOWN_SECONDS){
				return; //same crash site was reported recently - don't spam the webhook
			}
			self::$lastNotifiedSite[$site] = $now;

			$description = ($context !== null ? $context . "\n\n" : "") .
				$e->getMessage() . "\n" .
				"in " . Filesystem::cleanPath($e->getFile()) . ":" . $e->getLine() . "\n\n" .
				implode("\n", Utils::printableTrace($e->getTrace()));

			self::send("Unhandled " . get_class($e), $description);
		}catch(\Throwable){
			//never let crash reporting itself take the server down
		}
	}

	public static function notifyMessage(string $message) : void{
		try{
			self::send("Server notice", $message);
		}catch(\Throwable){
			//never let crash reporting itself take the server down
		}
	}

	private static function send(string $title, string $description) : void{
		$webhook = getenv("DISCORD_CRASH_WEBHOOK");
		if($webhook === false || trim($webhook) === ""){
			return;
		}
		$now = time();
		if($now - self::$lastSendTime < self::MIN_SEND_INTERVAL_SECONDS){
			return;
		}
		self::$lastSendTime = $now;

		$serverName = getenv("STRIFE_SERVER_NAME");
		if($serverName === false || trim($serverName) === ""){
			$serverName = gethostname();
			if($serverName === false || $serverName === ""){
				$serverName = "unknown";
			}
		}

		if(strlen($description) > self::MAX_DESCRIPTION_LENGTH){
			$description = substr($description, 0, self::MAX_DESCRIPTION_LENGTH) . "\n... (truncated)";
		}

		$payload = json_encode([
			"username" => $serverName,
			"embeds" => [
				[
					"title" => substr("[" . $serverName . "] " . $title, 0, 256),
					"description" => "```\n" . $description . "\n```",
					"color" => self::EMBED_COLOR_RED,
					"timestamp" => date(DATE_ATOM)
				]
			]
		]);
		if($payload === false){
			return;
		}

		Internet::postURL($webhook, $payload, self::HTTP_TIMEOUT_SECONDS, ["Content-Type: application/json"]);
	}
}
