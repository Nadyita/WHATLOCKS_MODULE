<?php

declare(strict_types=1);

namespace Budabot\User\Modules;

use Budabot\Core\CommandReply;
use Budabot\Core\DBRow;

/**
 * @Instance
 *
 * Commands this controller contains:
 *	@DefineCommand(
 *		command     = 'whatlocks',
 *		accessLevel = 'all',
 *		description = 'List skills locked by using items',
 *		help        = 'whatlocks.txt'
 *	)
 */
class WhatLocksController {
	
	public $moduleName;

	/**
	 * @var \Budabot\Core\Text $text
	 * @Inject
	 */
	public $text;
	
	/**
	 * @var \Budabot\Core\Util $util
	 * @Inject
	 */
	public $util;
	
	/**
	 * @var \Budabot\Core\DB $db
	 * @Inject
	 */
	public $db;

	/**
	 * This handler is called on bot startup.
	 * @Setup
	 */
	public function setup() {
		$this->db->loadSQLFile($this->moduleName, "what_locks");
	}

	/**
	 * Search for a list of skills that can be locked and how many items lock it
	 *
	 * @param string $message The full text as received by the bot
	 * @param string $channel "tell", "guild" or "priv"
	 * @param string $sender Name of the person sending the command
	 * @param \Budabot\Core\CommandReply $sendto Object to send the reply to
	 * @param string[] $args The parameters as parsed with the regexp
	 *
	 * @return void
	 *
	 * @HandlesCommand("whatlocks")
	 * @Matches("/^whatlocks$/i")
	 */
	public function whatLocksCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$sql = "
			SELECT
				s.name,COUNT(*) AS amount
			FROM
				what_locks wl
			JOIN
				skills s ON wl.skill_id=s.id
			JOIN
				aodb a ON (wl.item_id=a.lowid)
			GROUP BY
				s.name
			ORDER BY
				s.name ASC
		";
		$skills = $this->db->query($sql);
		$lines = array_map(function(DBRow $row) {
			return $this->text->alignNumber($row->amount, 3).
				" - ".
				$this->text->makeChatcmd($row->name, "/tell <myname> whatlocks $row->name");
		}, $skills);
		$blob = join("\n<pagebreak>", $lines);
		$pages = $this->text->makeBlob(
			count($lines) . " skills that can be locked by items",
			$blob
		);
		if (is_array($pages)) {
			$msg = array_map(function($page) {
				return $page . " found.";
			}, $pages);
		} else {
			$msg =  $pages . " found.";
		}
		$sendto->reply($msg);
	}

	/**
	 * Search the skill database for a skill
	 *
	 * @param string $skill The name of the skill searched for
	 * @return \Budabot\Core\DBRow[] All matching skills
	 */
	public function searchForSkill(string $skill): array {
		// check for exact match first, in order to disambiguate
		// between Bow and Bow special attack
		$results = $this->db->query(
			"SELECT DISTINCT id, name FROM skills WHERE LOWER(name)=?",
			strtolower($skill)
		);
		if (count($results) == 1) {
			return $results;
		}
		
		$tmp = explode(" ", $skill);
		list($query, $params) = $this->util->generateQueryFromParams($tmp, 'name');
		
		return $this->db->query(
			"SELECT DISTINCT id, name FROM skills WHERE $query",
			$params
		);
	}

	/**
	 * Get a dialog to choose which skill to search for locks
	 *
	 * @param \Budabot\Core\DBRow[] $skills A list of skills to choose from
	 * @return string The complete dialogue
	 */
	public function getSkillChoiceDialog(array $skills): string {
		$lines = array_map(function(DBRow $skill) {
			return $this->text->makeChatcmd(
				$skill->name,
				"/tell <myname> whatlocks {$skill->name}"
			);
		}, $skills);
		$msg = $this->text->makeBlob("WhatLocks - Choose Skill", join("\n", $lines));
		return $msg;
	}

	/**
	 * Search for a list of items that lock a specific skill
	 *
	 * @param string $message The full text as received by the bot
	 * @param string $channel "tell", "guild" or "priv"
	 * @param string $sender Name of the person sending the command
	 * @param \Budabot\Core\CommandReply $sendto Object to send the reply to
	 * @param string[] $args The parameters as parsed with the regexp
	 *
	 * @return void
	 *
	 * @HandlesCommand("whatlocks")
	 * @Matches("/^whatlocks\s+(.+)$/i")
	 */
	public function whatLocksSkillCommand(string $message, string $channel, string $sender, CommandReply $sendto, array $args): void {
		$skills = $this->searchForSkill($args[1]);
		if (count($skills) === 0) {
			$msg = "Could not find any skills matching <highlight>" . $args[1] . "<end>.";
			$sendto->reply($msg);
			return;
		} elseif (count($skills) > 1) {
			$msg = $this->getSkillChoiceDialog($skills);
			$sendto->reply($msg);
			return;
		}
		$sql = "
			SELECT
				w.*,
				a.lowid, a.highid, a.lowql, a.highql, a.name
			FROM
				what_locks w
			JOIN
				aodb a ON (w.item_id=a.lowid)
			WHERE
				w.skill_id = ?
			ORDER BY
				w.duration ASC
		";
		$rows = $this->db->query($sql, $skills[0]->id);
		if (count($rows) === 0) {
			$msg = "There is currently no item in the game locking ".
				"<highlight>{$skills[0]->name}<end>";
			$sendto->reply($msg);
			return;
		}
		$longestSuperflous = $this->prettyDuration((int)$rows[count($rows)-1]->duration)[0];
		$lines = array_map(
			function(DBRow $row) use ($longestSuperflous) {
				return $this->prettyDuration((int)$row->duration, (int)$longestSuperflous)[1].
					" - " .
					$this->text->makeItem($row->lowid, $row->highid, $row->lowql, $row->name);
			},
			$rows
		);
		$blob = join("\n<pagebreak>", $lines);
		$pages = $this->text->makeBlob(count($lines) . " items", $blob, "The following " . count($lines) . " items lock ". $skills[0]->name);
		if (is_array($pages)) {
			$msg = array_map(function($page) use ($skills) {
				return "{$page} found that lock {$skills[0]->name}";
			}, $pages);
		} else {
			$msg =  "{$pages} found that lock {$skills[0]->name}";
		}
		$sendto->reply($msg);
	}

	/**
	 * Get a pretty short string of a duration in seconds
	 *
	 * @param int $duration The ducation in seconds
	 * @param int $cutAway (optional) Cut away the first $cutAway characters
	 *                                from the returned string
	 * @return array<int,string> An array with 2 elements:
	 *                           How many characters are useless fill information,
	 *                           The prettified duration string
	 */
	public function prettyDuration(int $duration, int $cutAway=0): array {
		$short = strftime("%jd, %Hh %Mm %Ss", $duration);
		// Decrease days by 1, because the first day of the year is 1, but for
		// duration reasons, it must be 0
		$short = preg_replace_callback(
			"/^(\d+)/",
			function(array $match) {
				return $match[1] - 1;
			},
			$short
		);
		$superflous = strlen(preg_replace("/^([0, dhm]*).*/", "$1", $short));
		$valuable = strlen($short) - $superflous;
		$result = "<black>" . substr($short, $cutAway, $superflous-$cutAway) . "<end>".
			substr($short, -1 * $valuable);
		return array(
			$superflous,
			$result,
		);
	}
}
